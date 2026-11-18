<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\AI\Services\AiAnalysisService;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\LBA\Services\ExaminationWorkflow;
use Modules\LBA\Services\MicroscopyImageStore;

/**
 * TailAdmin case detail — one LBA examination's dossier, edit form and the
 * PRIVATE microscopy image intake.
 *
 * Every action is authorised ON THE SERVER, never by the client:
 *
 *  - the Clinic panel middleware has already bound the VERIFIED tenant database
 *    and proven a verified tenant context, so an examination is only ever
 *    resolved inside the caller's own tenant database;
 *  - the examination's branch must be one the identity may operate in, so a
 *    valid URL can never open another branch's case;
 *  - reading requires the module gate plus `exam.read` (via
 *    {@see ExaminationResource::canView()}), writing requires `exam.update` and
 *    an editable case, and moving the case requires the transition gate;
 *  - images flow through {@see MicroscopyImageStore}, which re-checks
 *    editability, a processable mode and a currently-granted image-processing
 *    consent before anything is written to the private disk.
 */
final class ExaminationController extends Controller
{
    public function show(Request $request, string $examination, ManagesBranchContext $branches): View
    {
        $record = $this->resolve($request, $examination, $branches);
        abort_unless(ExaminationResource::canView($record), 403);

        $events = $record->events()->get();

        // Actor ids are central user ids stored on the tenant-local event rows;
        // resolve their display names from the central users table.
        $actorNames = User::query()
            ->whereIn('id', $events->pluck('actor_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $operatorName = $record->operator_user_id !== null
            ? User::query()->whereKey($record->operator_user_id)->value('name')
            : null;

        return view('clinic.tailadmin.examination-show', [
            'examination' => $record->load(['patient', 'branch', 'appointment', 'consent']),
            'images' => $record->images()->get(),
            'events' => $events,
            'actorNames' => $actorNames,
            'operatorName' => $operatorName,
            'consentGranted' => $record->hasGrantedImageProcessingConsent(),
            'canEdit' => ExaminationResource::canEdit($record),
            'canAssess' => ! $record->isCancelled(),
            'canCapture' => $this->canCapture($record),
            'canSubmitReview' => ExaminationResource::canTransition($record, Examination::STATUS_REVIEW_REQUIRED),
            'canCancel' => ExaminationResource::canTransition($record, Examination::STATUS_CANCELLED),
            'uploadLimitKb' => (int) config('lba.images.max_kilobytes'),
            'analysisJobs' => $record->analysisJobs()->get(),
            'suggestions' => $record->detections()->orderByDesc('confidence')->limit(100)->get(),
            'aiEnabled' => app(AiAnalysisService::class)->aiEnabled(),
            'canStartAi' => ExaminationResource::canStartAiAnalysis($record),
            'canRetryAi' => ExaminationResource::canRetryAiAnalysis($record),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function edit(Request $request, string $examination, ManagesBranchContext $branches, ManagesTenantContext $tenants): View
    {
        $record = $this->resolve($request, $examination, $branches);
        abort_unless(ExaminationResource::canEdit($record), 403);

        $user = $request->user();

        return view('clinic.tailadmin.examination-edit', [
            'examination' => $record->load(['patient', 'branch']),
            'branchOptions' => $branches->eligibleOptions($user),
            'operatorOptions' => $this->staffOptions($tenants->currentTenantId($user)),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function update(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        ExaminationRegistrar $registrar,
    ): RedirectResponse {
        $record = $this->resolve($request, $examination, $branches);
        abort_unless(ExaminationResource::canEdit($record), 403);

        $branchIds = array_map(
            static fn ($option): string => (string) $option->id,
            $branches->eligibleOptions($request->user()),
        );

        $data = $request->validate([
            'branch_id' => ['required', 'ulid', Rule::in($branchIds)],
            // `operator_user_id` is a CENTRAL user id (an integer key stored as a
            // string on the tenant-local row), never a tenant ULID.
            'operator_user_id' => ['nullable', 'integer'],
            'mode' => ['required', Rule::in(Examination::MODES)],
            'captured_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'instrument' => ['nullable', 'string', 'max:120'],
            'magnification' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'record_consent' => ['sometimes', 'boolean'],
        ]);

        try {
            $registrar->update($record, $data, $request->user());
        } catch (ExaminationException $exception) {
            throw ValidationException::withMessages(['notes' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.examinations.show', $record)
            ->with('success', __('lba.exams.detail.updated'));
    }

    /**
     * The private microscopy still intake. The uploaded original is validated by
     * its real content and written to the private `lba` disk; the store refuses
     * anything without an editable case, a processable mode and a currently
     * granted image-processing consent.
     */
    public function storeImage(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        MicroscopyImageStore $store,
    ): RedirectResponse {
        $record = $this->resolve($request, $examination, $branches);
        abort_unless(ExaminationResource::canEdit($record), 403);

        $data = $request->validate([
            'image' => ['required', 'file', 'max:'.(int) config('lba.images.max_kilobytes')],
            'instrument_label' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $store->store($record, $data['image'], $request->user(), [
                'source' => ExaminationImage::SOURCE_MANUAL_IMPORT,
                'captured_at' => now(),
                'instrument_label' => $data['instrument_label'] ?? null,
            ]);
        } catch (ExaminationException $exception) {
            throw ValidationException::withMessages(['image' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.examinations.show', $record)
            ->with('success', __('lba.exams.detail.image_stored'));
    }

    /**
     * Move the case through its validated workflow (submit for review / cancel).
     * The transition itself is re-validated by {@see ExaminationWorkflow}.
     */
    public function transition(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        ExaminationWorkflow $workflow,
    ): RedirectResponse {
        $record = $this->resolve($request, $examination, $branches);

        $data = $request->validate([
            'to' => ['required', Rule::in([
                Examination::STATUS_REVIEW_REQUIRED,
                Examination::STATUS_CANCELLED,
            ])],
        ]);

        abort_unless(ExaminationResource::canTransition($record, $data['to']), 403);

        try {
            $workflow->transition($record, $data['to'], $request->user());
        } catch (ExaminationException $exception) {
            throw ValidationException::withMessages(['to' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.examinations.show', $record)
            ->with('success', __('lba.exams.status_changed'));
    }

    /**
     * Start an idempotent AI vision analysis for one image, from the normal
     * TailAdmin route. The server-side gate ({@see ExaminationResource::canStartAiAnalysis()})
     * proves the module, the `exam_ai.start` permission, an AI-capable mode, an
     * editable case, a currently-granted image-processing consent and an enabled
     * provider; {@see AiAnalysisService} re-checks all of it and never bills a
     * manual/zero-price job.
     */
    public function startAnalysis(
        Request $request,
        string $examination,
        string $image,
        ManagesBranchContext $branches,
        AiAnalysisService $analysis,
    ): RedirectResponse {
        $record = $this->resolve($request, $examination, $branches);

        abort_unless(ExaminationResource::canStartAiAnalysis($record), 403);

        // The image is resolved through THIS examination's own relation, so a
        // cross-examination or cross-tenant image id never resolves.
        $imageRecord = $record->images()->whereKey($image)->firstOrFail();

        try {
            $analysis->startAnalysis($record, $imageRecord, $request->user());
        } catch (AiAnalysisException $exception) {
            throw ValidationException::withMessages(['analysis' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.examinations.show', $record)
            ->with('success', __('lba.exams.ai.queued'));
    }

    /**
     * Re-queue a FAILED analysis job (explicit, idempotent, attempt-limited).
     */
    public function retryAnalysis(
        Request $request,
        string $examination,
        string $job,
        ManagesBranchContext $branches,
        AiAnalysisService $analysis,
    ): RedirectResponse {
        $record = $this->resolve($request, $examination, $branches);

        abort_unless(ExaminationResource::canRetryAiAnalysis($record), 403);

        /** @var AnalysisJob $jobRecord */
        $jobRecord = $record->analysisJobs()->whereKey($job)->firstOrFail();

        try {
            $analysis->retryAnalysis($jobRecord, $request->user());
        } catch (AiAnalysisException $exception) {
            throw ValidationException::withMessages(['analysis' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.examinations.show', $record)
            ->with('success', __('lba.exams.ai.retry_queued'));
    }

    /**
     * Resolve one examination INSIDE the verified tenant database and prove the
     * identity may operate in its branch. A missing id (including one that
     * belongs to another tenant) is a 404; a foreign branch is a 403.
     */
    private function resolve(Request $request, string $examination, ManagesBranchContext $branches): Examination
    {
        $record = Examination::query()->whereKey($examination)->firstOrFail();

        $branchIds = array_map(
            static fn ($option): string => (string) $option->id,
            $branches->eligibleOptions($request->user()),
        );

        abort_unless(in_array((string) $record->branch_id, $branchIds, true), 403);

        return $record;
    }

    /**
     * Whether a still may be captured/stored right now. Mirrors the store's own
     * gates so the UI can offer the action only when the server would accept it;
     * the store re-checks everything.
     */
    private function canCapture(Examination $record): bool
    {
        return ExaminationResource::canEdit($record)
            && in_array($record->mode, Examination::PROCESSABLE_MODES, true)
            && $record->hasGrantedImageProcessingConsent();
    }

    /**
     * Active staff members of the verified tenant, for the operator picker.
     *
     * @return array<string, string>
     */
    private function staffOptions(?string $tenantId): array
    {
        if ($tenantId === null || app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (TenantMembership $membership): array => [
                (string) $membership->user_id => $membership->user?->name ?? (string) $membership->user_id,
            ])
            ->all();
    }
}
