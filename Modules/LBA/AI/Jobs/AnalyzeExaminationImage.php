<?php

namespace Modules\LBA\AI\Jobs;

use App\Platform\Tenancy\Jobs\Middleware\TenantDatabaseContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\LBA\AI\Services\AiAnalysisService;
use Throwable;

/**
 * Phase 5 — the background AI vision analysis worker job.
 *
 * It carries ONLY the verified tenant id and the job ULID (both opaque, no
 * patient data), and binds the verified tenant database for the duration of the
 * run through {@see TenantDatabaseContext}. The middleware ALWAYS releases the
 * connection afterwards, so the next queued job — possibly another tenant's —
 * can never inherit this tenant's context.
 *
 * Automatic Laravel retries are disabled (`$tries = 1`): a failure is recorded
 * as a domain state on the job and re-running is an explicit, idempotent,
 * attempt-limited action (see {@see AiAnalysisService::retryAnalysis()}).
 */
final class AnalyzeExaminationImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $jobId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantDatabaseContext($this->tenantId)];
    }

    public function handle(AiAnalysisService $service): void
    {
        try {
            $service->processJob($this->jobId);
        } catch (Throwable $exception) {
            // Safety net: any unexpected failure is recorded against the job and
            // re-thrown for logging — the original image is left untouched.
            $service->failAnalysisById($this->jobId, 'analysis_error');

            throw $exception;
        }
    }
}
