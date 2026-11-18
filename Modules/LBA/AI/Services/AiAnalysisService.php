<?php

namespace Modules\LBA\AI\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\LBA\AI\Billing\AiBillingPolicy;
use Modules\LBA\AI\Contracts\ReservesAiCredits;
use Modules\LBA\AI\Contracts\VisionAnalyzer;
use Modules\LBA\AI\Dto\VisionRequest;
use Modules\LBA\AI\Dto\VisionResult;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\AI\Jobs\AnalyzeExaminationImage;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\ExaminationWorkflow;

/**
 * Phase 5 — orchestration of the asynchronous AI vision analysis pipeline.
 *
 * Responsibilities:
 *  - gate analysis behind an explicit image-processing consent, a compatible
 *    mode and an editable examination;
 *  - create an IDEMPOTENT job (unique key per provider/protocol/exam/image) so a
 *    repeated request can never enqueue a second job or duplicate detections;
 *  - dispatch the job through the queue with a verified tenant context;
 *  - validate the gateway response and persist detections as UNREVIEWED
 *    suggestions in their own table (never as a human annotation, never
 *    approved, never published);
 *  - record safe, non-identifying job failures while leaving original images
 *    untouched.
 *
 * Every write runs on the VERIFIED tenant connection.
 *
 * Phase 6 adds billing without an atomic cross-database transaction: the job is
 * created PENDING, credits are reserved centrally through {@see ReservesAiCredits}
 * (the ONLY seam that touches the central wallet), and only then is the job
 * marked QUEUED and dispatched. Capture/release happen AFTER the tenant result
 * is durably persisted, so a crash between the two databases leaves a durable,
 * reconcilable state rather than a silent loss or a double charge.
 */
final class AiAnalysisService
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly ResolvesTenantDatabase $databases,
        private readonly VisionAnalyzer $analyzer,
        private readonly ReservesAiCredits $credits,
        private readonly ExaminationWorkflow $workflow,
    ) {}

    /**
     * Whether AI processing may run at all in this environment.
     *
     * Production stays OFF until `lba.ai.enabled` is explicitly turned on with
     * an approved policy. The non-production sandbox may run non-billable
     * synthetic inference — but `LBA_AI_SANDBOX` can NEVER enable AI in
     * production, even if the flag is accidentally set.
     */
    public function aiEnabled(): bool
    {
        if ($this->environmentIsProduction()) {
            return (bool) config('lba.ai.enabled', false);
        }

        return (bool) config('lba.ai.enabled', false) || (bool) config('lba.ai.sandbox', false);
    }

    public function analyzerName(): string
    {
        return $this->analyzer->provider();
    }

    /**
     * Create (or return the existing) idempotent analysis job and dispatch it.
     *
     * @throws AiAnalysisException
     */
    public function startAnalysis(Examination $examination, ExaminationImage $image, ?User $actor = null): AnalysisJob
    {
        $this->assertEnabled();
        $this->assertImageOnExamination($examination, $image);

        if (! $examination->isAiCapable()) {
            throw AiAnalysisException::modeNotProcessable((string) $examination->mode);
        }

        if (! $examination->isEditable()) {
            throw AiAnalysisException::notEditable((string) $examination->status);
        }

        if (! $examination->hasGrantedImageProcessingConsent()) {
            throw AiAnalysisException::consentRequired();
        }

        $protocol = (string) config('lba.ai.protocol_version', '1');
        $key = $this->idempotencyKey($this->analyzer->provider(), $protocol, $examination, $image);

        $existing = AnalysisJob::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            // Idempotent: the same image never produces a second job — and so
            // never a duplicate set of detections.
            return $existing;
        }

        $job = $this->tenantTransaction(function () use ($examination, $image, $actor, $key): AnalysisJob {
            // Step 1 — create the PENDING job in the TENANT database.
            $job = AnalysisJob::query()->create([
                'examination_id' => $examination->getKey(),
                'image_id' => $image->getKey(),
                'request_ref' => (string) Str::ulid(),
                'status' => AnalysisJob::STATUS_PENDING,
                'provider' => $this->analyzer->provider(),
                'model_name' => (string) config('lba.ai.model.name'),
                'model_version' => (string) config('lba.ai.model.version'),
                'attempts' => 1,
                'idempotency_key' => $key,
                'requested_by' => $actor?->getKey(),
                'billable' => false,
            ]);

            // Steps 2–3 — decide billability and RESERVE credits centrally. A
            // real, billable job with no approved price or no balance throws,
            // rolling this tenant transaction back so nothing is charged and no
            // half-created job survives. Manual/synthetic jobs are 0 credits.
            $this->credits->reserve($job);

            // Step 4 — only now is the job ready to be queued.
            $job->forceFill(['status' => AnalysisJob::STATUS_QUEUED])->save();

            $this->workflow->recordEvent(
                $examination,
                (string) $examination->status,
                (string) $examination->status,
                $actor,
                'ai_analysis_queued:'.$job->getKey(),
            );

            return $job;
        });

        // Step 5 — dispatch ONLY after the reservation committed successfully.
        $this->dispatch($job);

        return $job;
    }

    /**
     * Re-queue a FAILED job, idempotently and within a safe attempt limit.
     *
     * @throws AiAnalysisException
     */
    public function retryAnalysis(AnalysisJob $job, ?User $actor = null): AnalysisJob
    {
        $this->assertEnabled();

        if (! $job->isRetryable()) {
            throw AiAnalysisException::stateNotRetryable((string) $job->status);
        }

        $limit = (int) config('lba.ai.max_attempts', 3);

        if ((int) $job->attempts >= $limit) {
            throw AiAnalysisException::retryLimitReached($limit);
        }

        $job = $this->tenantTransaction(function () use ($job): AnalysisJob {
            $fresh = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isRetryable()) {
                return $fresh;
            }

            $fresh->forceFill([
                'status' => AnalysisJob::STATUS_QUEUED,
                'error_code' => null,
                'attempts' => ((int) $fresh->attempts) + 1,
                'started_at' => null,
                'completed_at' => null,
                // The previous attempt's reservation was released, so this
                // attempt re-takes it under a new, attempt-scoped key.
                'credits_state' => null,
            ])->save();

            // Re-reserve safely: a billable job whose reservation was released
            // must take a fresh reservation; a non-billable job stays at zero.
            $this->credits->reserve($fresh);

            return $fresh;
        });

        if ($job->status === AnalysisJob::STATUS_QUEUED) {
            $this->dispatch($job);
        }

        return $job;
    }

    /**
     * Run one job end-to-end. Invoked by {@see AnalyzeExaminationImage} inside a
     * verified tenant context.
     */
    public function processJob(string $jobId): void
    {
        $job = AnalysisJob::query()->whereKey($jobId)->first();

        if ($job === null || ! $job->isInFlight()) {
            return;
        }

        $this->tenantTransaction(function () use ($job): void {
            $fresh = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->first();

            if ($fresh !== null && $fresh->status === AnalysisJob::STATUS_QUEUED) {
                $fresh->forceFill([
                    'status' => AnalysisJob::STATUS_PROCESSING,
                    'started_at' => now(),
                ])->save();
            }
        });

        $image = ExaminationImage::query()->whereKey($job->image_id)->first();

        if ($image === null) {
            $this->failAnalysis($job, 'image_unreadable');

            return;
        }

        $contents = $this->readImage($image);

        if ($contents === null) {
            $this->failAnalysis($job, 'image_unreadable');

            return;
        }

        $request = new VisionRequest(
            jobReference: (string) $job->request_ref,
            imageReference: (string) $image->getKey(),
            contents: $contents,
            checksum: (string) $image->checksum,
            mimeType: (string) $image->mime_type,
            protocolVersion: (string) config('lba.ai.protocol_version', '1'),
        );

        try {
            $result = $this->analyzer->analyze($request);
        } catch (AiAnalysisException $exception) {
            // A gateway outage or an invalid response is a recorded, retryable
            // job failure. Originals on the private disk are never touched.
            $this->failAnalysis($job, $exception->errorCode());

            return;
        }

        $this->completeAnalysis($job, $result);
    }

    /**
     * Persist a validated result as UNREVIEWED suggestions. Idempotent: a job
     * that already completed never writes a second set of detections.
     *
     * Phase 6 — the result is persisted FIRST (tenant database), then the credit
     * reservation is settled ONCE. A synthetic result is never billed: its
     * reservation is released instead of captured, so synthetic/mock inference
     * always costs zero.
     */
    public function completeAnalysis(AnalysisJob $job, VisionResult $result): void
    {
        $fresh = $this->tenantTransaction(function () use ($job, $result): ?AnalysisJob {
            $fresh = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return null;
            }

            if ($fresh->status !== AnalysisJob::STATUS_COMPLETED) {
                $alreadyStored = Detection::query()->where('job_id', $fresh->getKey())->exists();

                if (! $alreadyStored) {
                    foreach ($result->detections as $detection) {
                        Detection::query()->create([
                            'examination_id' => $fresh->examination_id,
                            'image_id' => $fresh->image_id,
                            'job_id' => $fresh->getKey(),
                            'provenance' => Detection::PROVENANCE_AI,
                            'label' => $detection['label'],
                            'geometry' => $detection['geometry'],
                            'confidence' => $detection['confidence'],
                            'model_name' => $result->modelName,
                            'model_version' => $result->modelVersion,
                            'quality_flags' => $detection['quality_flags'],
                        ]);
                    }
                }

                $fresh->forceFill([
                    'status' => AnalysisJob::STATUS_COMPLETED,
                    'model_name' => $result->modelName,
                    'model_version' => $result->modelVersion,
                    'error_code' => null,
                    'completed_at' => now(),
                ])->save();

                $examination = $fresh->examination()->first();

                if ($examination !== null) {
                    $this->workflow->recordEvent(
                        $examination,
                        (string) $examination->status,
                        (string) $examination->status,
                        null,
                        'ai_analysis_completed:'.$fresh->getKey(),
                    );
                }
            }

            return $fresh;
        });

        if ($fresh === null) {
            return;
        }

        // Step 6 — settle the reservation AFTER the tenant result is durable.
        // Both operations are idempotent, so a repeated completion never
        // double-charges and a stranded reservation on a completed job is
        // settled by the reconciliation command.
        if ($result->synthetic) {
            $this->credits->release($fresh);
        } else {
            $this->credits->capture($fresh);
        }
    }

    /**
     * Record a safe, non-identifying failure. A completed job is never
     * downgraded and the original image is never modified.
     *
     * Phase 6 — a definitive failure releases the reservation ONCE, restoring
     * the available balance; a repeated failure is idempotent.
     */
    public function failAnalysis(AnalysisJob $job, string $errorCode): void
    {
        $fresh = $this->tenantTransaction(function () use ($job, $errorCode): ?AnalysisJob {
            $fresh = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->status === AnalysisJob::STATUS_COMPLETED) {
                return $fresh;
            }

            $fresh->forceFill([
                'status' => AnalysisJob::STATUS_FAILED,
                'error_code' => $errorCode,
                'completed_at' => now(),
            ])->save();

            return $fresh;
        });

        if ($fresh !== null && $fresh->status === AnalysisJob::STATUS_FAILED) {
            // Step 7 — release the reservation so a failed analysis never
            // deducts credits; safe to repeat.
            $this->credits->release($fresh);
        }
    }

    /**
     * Record a failure by job id (used by the worker's safety net).
     */
    public function failAnalysisById(string $jobId, string $errorCode): void
    {
        $job = AnalysisJob::query()->whereKey($jobId)->first();

        if ($job === null) {
            return;
        }

        $this->failAnalysis($job, $errorCode);
    }

    private function dispatch(AnalysisJob $job): void
    {
        $tenantId = $this->databases->current();

        if ($tenantId === null) {
            throw AiAnalysisException::imageUnreadable();
        }

        AnalyzeExaminationImage::dispatch($tenantId, (string) $job->getKey());
    }

    private function readImage(ExaminationImage $image): ?string
    {
        $disk = Storage::disk((string) $image->disk);

        if (! $disk->exists((string) $image->object_key)) {
            return null;
        }

        $contents = $disk->get((string) $image->object_key);

        return $contents === null ? null : (string) $contents;
    }

    private function assertEnabled(): void
    {
        if (! $this->aiEnabled()) {
            throw AiAnalysisException::disabled();
        }

        // A synthetic/mock provider can NEVER run in production, even if AI was
        // explicitly enabled: production requires a real, validated provider.
        if ($this->environmentIsProduction() && $this->providerIsSynthetic()) {
            throw AiAnalysisException::productionMockProhibited();
        }
    }

    private function environmentIsProduction(): bool
    {
        return app()->environment('production');
    }

    private function providerIsSynthetic(): bool
    {
        return in_array($this->analyzer->provider(), AiBillingPolicy::SYNTHETIC_PROVIDERS, true);
    }

    private function assertImageOnExamination(Examination $examination, ExaminationImage $image): void
    {
        if ((string) $image->examination_id !== (string) $examination->getKey()) {
            throw AiAnalysisException::imageNotOnExamination();
        }
    }

    private function idempotencyKey(string $provider, string $protocol, Examination $examination, ExaminationImage $image): string
    {
        return hash('sha256', implode('|', [
            $provider,
            $protocol,
            (string) $examination->getKey(),
            (string) $image->getKey(),
        ]));
    }
}
