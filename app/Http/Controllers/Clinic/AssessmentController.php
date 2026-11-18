<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LBA\Exceptions\AssessmentException;
use Modules\LBA\Exceptions\ReviewException;
use Modules\LBA\Filament\Pages\ReviewerInbox;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentEvidenceLink;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\BrandDirectory;

/**
 * TailAdmin assessment recording, human review and report release.
 *
 * This is the TailAdmin (Blade) counterpart of the Filament Phase 7A/7B pages,
 * and it reuses the SAME domain services so no rule is duplicated or weakened:
 *
 *  - the Clinic panel middleware has already bound the VERIFIED tenant database
 *    and proven a verified tenant context, so an examination or assessment is
 *    only ever resolved inside the caller's own tenant database;
 *  - the record's branch must be one the identity may operate in, so a valid URL
 *    can never open another branch's case;
 *  - reading requires the module gate plus `exam.read`, recording requires
 *    `exam.update` and an editable assessment, review requires `exam.review`,
 *    and approve/release require `report.approve` — all re-derived on the server;
 *  - every write flows through {@see AssessmentRecorder} / {@see AssessmentReviewer},
 *    which re-check editability, the module gate, the verified tenant/branch
 *    context and separation of duties, and append the audit trail;
 *  - AI detections are shown as INFORMATION only and are never promoted to a
 *    category or a patient-facing finding.
 */
final class AssessmentController extends Controller
{
    // ---------------------------------------------------- Operator: recording

    public function show(Request $request, string $examination, ManagesBranchContext $branches): View
    {
        $record = $this->resolveExamination($request, $examination, $branches);
        abort_unless(ExaminationResource::canView($record), 403);

        $user = $request->user();
        $recorder = app(AssessmentRecorder::class);

        // A read-only identity loads only what exists; it never creates an
        // assessment nor provisions the reference catalog.
        $canRecord = ExaminationResource::canRecord() && ! $record->isCancelled();

        try {
            $assessment = $canRecord ? $recorder->openFor($record, $user) : $recorder->findFor($record);
        } catch (AssessmentException) {
            $assessment = $recorder->findFor($record);
        }

        return view('clinic.tailadmin.assessment-show', [
            'examination' => $record,
            'assessment' => $assessment,
            'items' => $assessment?->items()->get() ?? collect(),
            'evidenceCounts' => $this->evidenceCounts($assessment),
            'images' => $record->images()->get(),
            'aiSuggestions' => $this->aiSuggestions($record),
            'programPhaseOptions' => $this->programPhaseOptions(),
            'brandOptions' => $this->brandOptions(),
            'canRecord' => $canRecord && $assessment?->isEditable() === true,
            'canSubmit' => $canRecord
                && $assessment !== null
                && $assessment->isEditable()
                && $assessment->canTransitionTo(Assessment::STATUS_SUBMITTED_FOR_REVIEW),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function setCategory(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        AssessmentRecorder $recorder,
    ): RedirectResponse {
        $record = $this->resolveExamination($request, $examination, $branches);
        $assessment = $this->editableAssessment($request, $record);

        $data = $request->validate([
            'indicator_key' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(AssessmentItem::CATEGORIES)],
        ]);

        try {
            $recorder->setCategory($assessment, $data['indicator_key'], $data['category'], $request->user());
        } catch (AssessmentException $exception) {
            throw ValidationException::withMessages(['category' => $exception->getMessage()]);
        }

        return back()->with('success', __('lba.assessment.draft_saved'));
    }

    public function saveItemNotes(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        AssessmentRecorder $recorder,
    ): RedirectResponse {
        $record = $this->resolveExamination($request, $examination, $branches);
        $assessment = $this->editableAssessment($request, $record);

        $data = $request->validate([
            'indicator_key' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $recorder->updateNotes(
                $assessment,
                $data['indicator_key'],
                $data['notes'] ?? null,
                $request->user(),
            );
        } catch (AssessmentException $exception) {
            throw ValidationException::withMessages(['notes' => $exception->getMessage()]);
        }

        return back()->with('success', __('lba.assessment.notes_saved'));
    }

    public function attachEvidence(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        AssessmentRecorder $recorder,
    ): RedirectResponse {
        $record = $this->resolveExamination($request, $examination, $branches);
        $assessment = $this->editableAssessment($request, $record);

        $data = $request->validate([
            'indicator_key' => ['required', 'string', 'max:120'],
            'image_id' => ['required', 'string'],
        ]);

        try {
            $recorder->attachEvidence(
                $assessment,
                $data['indicator_key'],
                AssessmentEvidenceLink::TYPE_IMAGE,
                $data['image_id'],
                $request->user(),
            );
        } catch (AssessmentException $exception) {
            throw ValidationException::withMessages(['image_id' => $exception->getMessage()]);
        }

        return back()->with('success', __('lba.assessment.evidence_attached'));
    }

    public function setPhase(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        AssessmentRecorder $recorder,
    ): RedirectResponse {
        $record = $this->resolveExamination($request, $examination, $branches);
        $assessment = $this->editableAssessment($request, $record);

        $data = $request->validate([
            'program_phase' => ['required', Rule::in(Assessment::PROGRAM_PHASES)],
        ]);

        try {
            $recorder->setProgramPhase($assessment, $data['program_phase'], $request->user());
        } catch (AssessmentException $exception) {
            throw ValidationException::withMessages(['program_phase' => $exception->getMessage()]);
        }

        return back()->with('success', __('lba.assessment.draft_saved'));
    }

    public function setBrand(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        AssessmentRecorder $recorder,
    ): RedirectResponse {
        $record = $this->resolveExamination($request, $examination, $branches);
        $assessment = $this->editableAssessment($request, $record);

        $tenantId = app(ResolvesTenantDatabase::class)->current();

        $data = $request->validate([
            'brand_id' => ['required', 'string', Rule::in(array_keys($this->brandOptions()))],
        ]);

        abort_if($tenantId === null, 403);

        try {
            $recorder->selectBrand($assessment, $tenantId, $data['brand_id'], $request->user());
        } catch (AssessmentException $exception) {
            throw ValidationException::withMessages(['brand_id' => $exception->getMessage()]);
        }

        return back()->with('success', __('lba.assessment.draft_saved'));
    }

    public function saveDraft(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        AssessmentRecorder $recorder,
    ): RedirectResponse {
        $record = $this->resolveExamination($request, $examination, $branches);
        $assessment = $this->editableAssessment($request, $record);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $recorder->saveSummaryNotes(
                $assessment,
                ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
                $request->user(),
            );
        } catch (AssessmentException $exception) {
            throw ValidationException::withMessages(['notes' => $exception->getMessage()]);
        }

        return back()->with('success', __('lba.assessment.draft_saved'));
    }

    public function submitForReview(
        Request $request,
        string $examination,
        ManagesBranchContext $branches,
        AssessmentRecorder $recorder,
    ): RedirectResponse {
        $record = $this->resolveExamination($request, $examination, $branches);
        $assessment = $this->editableAssessment($request, $record);

        abort_unless($assessment->canTransitionTo(Assessment::STATUS_SUBMITTED_FOR_REVIEW), 403);

        try {
            $recorder->submitForReview($assessment, $request->user());
        } catch (AssessmentException $exception) {
            throw ValidationException::withMessages(['assessment' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.assessments.show', $record)
            ->with('success', __('lba.assessment.submitted'));
    }

    // ------------------------------------------------------- Reviewer: inbox

    public function index(Request $request, ManagesBranchContext $branches): View
    {
        abort_unless(ReviewerInbox::canAccess(), 403);

        $branchIds = $this->accessibleBranchIds($request, $branches);

        $pending = $this->scopedAssessments($branchIds, [Assessment::STATUS_SUBMITTED_FOR_REVIEW])
            ->orderBy('submitted_at')->orderBy('created_at')->get();

        $decided = $this->scopedAssessments($branchIds, [
            Assessment::STATUS_REVIEWED,
            Assessment::STATUS_REJECTED,
            Assessment::STATUS_RETURNED_FOR_CHANGES,
        ])->orderByDesc('reviewed_at')->orderByDesc('created_at')->limit(20)->get();

        return view('clinic.tailadmin.reviews-index', [
            'pending' => $pending,
            'decided' => $decided,
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function review(Request $request, string $assessment, ManagesBranchContext $branches): View
    {
        $record = $this->resolveAssessment($request, $assessment, $branches);
        $examination = $record->examination;

        abort_unless($examination !== null && ExaminationResource::canReview(), 403);

        $canApprove = ExaminationResource::canApproveReport();

        return view('clinic.tailadmin.review-show', [
            'assessment' => $record,
            'examination' => $examination,
            'items' => $record->items()->get(),
            'evidenceByIndicator' => $this->evidenceByIndicator($record),
            'images' => $examination?->images()->get() ?? collect(),
            'aiSuggestions' => $examination !== null ? $this->aiSuggestions($examination) : collect(),
            'reviews' => $record->reviews()->get(),
            'reportVersions' => $record->reportVersions()->get(),
            'reportEvents' => $record->reportEvents()->get(),
            'brandName' => $this->brandName($record),
            'canDecide' => $record->status === Assessment::STATUS_SUBMITTED_FOR_REVIEW,
            'canApprove' => $canApprove && $record->brand_id !== null && ! $this->isSelfApproval($request, $record),
            'canRelease' => $canApprove && $record->status === Assessment::STATUS_REVIEWED,
            'selfApproval' => $this->isSelfApproval($request, $record),
            'localeOptions' => $this->localeOptions(),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function decide(
        Request $request,
        string $assessment,
        ManagesBranchContext $branches,
        AssessmentReviewer $reviewer,
    ): RedirectResponse {
        $record = $this->resolveAssessment($request, $assessment, $branches);

        abort_unless(ExaminationResource::canReview(), 403);
        abort_unless($record->status === Assessment::STATUS_SUBMITTED_FOR_REVIEW, 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['return', 'reject', 'approve'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'locale' => ['nullable', 'string', 'max:20'],
        ]);

        $actor = $request->user();
        $notes = ($data['notes'] ?? '') !== '' ? $data['notes'] : null;

        try {
            if ($data['decision'] === 'approve') {
                $tenantId = app(ResolvesTenantDatabase::class)->current();
                abort_if($tenantId === null, 403);
                abort_unless(ExaminationResource::canApproveReport(), 403);

                $reviewer->approve($record, $tenantId, $actor, $notes, $data['locale'] ?? null);
                $message = __('lba.review.approved');
            } elseif ($data['decision'] === 'reject') {
                $reviewer->reject($record, $actor, $notes);
                $message = __('lba.review.rejected');
            } else {
                $reviewer->returnForChanges($record, $actor, $notes);
                $message = __('lba.review.returned');
            }
        } catch (ReviewException $exception) {
            throw ValidationException::withMessages(['decision' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.reviews.show', $record)->with('success', $message);
    }

    public function release(
        Request $request,
        string $assessment,
        string $report,
        ManagesBranchContext $branches,
        AssessmentReviewer $reviewer,
    ): RedirectResponse {
        $record = $this->resolveAssessment($request, $assessment, $branches);

        abort_unless(ExaminationResource::canApproveReport(), 403);

        // The version is resolved through THIS assessment's own versions only, so
        // a cross-assessment id never resolves.
        $version = $record->reportVersions()->whereKey($report)->firstOrFail();
        $tenantId = app(ResolvesTenantDatabase::class)->current();
        abort_if($tenantId === null, 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $reviewer->releaseToPatient(
                $version,
                $tenantId,
                $request->user(),
                ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
            );
        } catch (ReviewException $exception) {
            throw ValidationException::withMessages(['report' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.reviews.show', $record)
            ->with('success', __('lba.review.released'));
    }

    // ------------------------------------------------------------- Resolution

    /**
     * Resolve one examination INSIDE the verified tenant database and prove the
     * identity may operate in its branch. A missing id (including another
     * tenant's) is a 404; a foreign branch is a 403.
     */
    private function resolveExamination(Request $request, string $examination, ManagesBranchContext $branches): Examination
    {
        $record = Examination::query()->whereKey($examination)->firstOrFail();

        abort_unless(
            in_array((string) $record->branch_id, $this->accessibleBranchIds($request, $branches), true),
            403,
        );

        return $record;
    }

    /**
     * Resolve one assessment INSIDE the verified tenant database and prove the
     * identity may operate in its examination's branch.
     */
    private function resolveAssessment(Request $request, string $assessment, ManagesBranchContext $branches): Assessment
    {
        $record = Assessment::query()
            ->with(['examination.patient', 'examination.branch'])
            ->whereKey($assessment)
            ->firstOrFail();

        $examination = $record->examination;

        abort_if($examination === null, 404);

        abort_unless(
            in_array((string) $examination->branch_id, $this->accessibleBranchIds($request, $branches), true),
            403,
        );

        return $record;
    }

    /**
     * The assessment of an examination when the acting identity may RECORD and
     * the assessment is still editable; otherwise the request is refused on the
     * server. UI visibility is never the authorization boundary.
     */
    private function editableAssessment(Request $request, Examination $examination): Assessment
    {
        abort_unless(ExaminationResource::canRecord(), 403);

        $assessment = Assessment::query()
            ->where('examination_id', $examination->getKey())
            ->orderByDesc('created_at')
            ->first();

        abort_if($assessment === null, 404);
        abort_unless($assessment->isEditable(), 403);

        return $assessment;
    }

    // ------------------------------------------------------------------- Data

    /**
     * @return Builder<Assessment>
     */
    private function scopedAssessments(array $branchIds, array $statuses): Builder
    {
        return Assessment::query()
            ->with(['examination.patient', 'examination.branch'])
            ->whereIn('status', $statuses)
            ->when(
                $branchIds !== [],
                fn (Builder $query): Builder => $query->whereHas(
                    'examination',
                    fn (Builder $inner): Builder => $inner->whereIn('branch_id', $branchIds),
                ),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            );
    }

    /**
     * @return list<string>
     */
    private function accessibleBranchIds(Request $request, ManagesBranchContext $branches): array
    {
        return array_map(
            static fn ($option): string => (string) $option->id,
            $branches->eligibleOptions($request->user()),
        );
    }

    /**
     * @return array<string, int> indicator key => number of attached evidence links
     */
    private function evidenceCounts(?Assessment $assessment): array
    {
        if ($assessment === null) {
            return [];
        }

        return $assessment->evidenceLinks()
            ->get()
            ->groupBy('indicator_key')
            ->map(fn ($group): int => $group->count())
            ->all();
    }

    /**
     * @return array<string, list<array{type: string, provenance: string}>>
     */
    private function evidenceByIndicator(Assessment $assessment): array
    {
        $grouped = [];

        foreach ($assessment->evidenceLinks()->get() as $link) {
            $grouped[(string) $link->indicator_key][] = [
                'type' => (string) $link->evidence_type,
                'provenance' => (string) $link->provenance,
            ];
        }

        return $grouped;
    }

    /**
     * Unreviewed AI SUGGESTIONS for the examination — information only, never a
     * category or a clinical finding.
     *
     * @return Collection<int, Detection>
     */
    private function aiSuggestions(Examination $examination): Collection
    {
        return Detection::query()
            ->where('examination_id', $examination->getKey())
            ->orderByDesc('confidence')
            ->limit(50)
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function programPhaseOptions(): array
    {
        return [
            Assessment::PROGRAM_CLEANSING => __('lba.assessment.phase_cleansing'),
            Assessment::PROGRAM_BALANCING => __('lba.assessment.phase_balancing'),
            Assessment::PROGRAM_PROTECTING => __('lba.assessment.phase_protecting'),
            Assessment::PROGRAM_MAINTENANCE => __('lba.assessment.phase_maintenance'),
            Assessment::PROGRAM_NOT_SPECIFIED => __('lba.assessment.phase_not_specified'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function brandOptions(): array
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null) {
            return [];
        }

        return app(BrandDirectory::class)->brandOptions($tenantId);
    }

    /**
     * @return array<string, string>
     */
    private function localeOptions(): array
    {
        $labels = (array) config('localization.labels', []);

        $options = ['' => __('lba.review.locale_brand_default')];

        foreach ((array) config('lba.reports.locales', ['id', 'ms-MY', 'en']) as $locale) {
            $options[$locale] = $labels[$locale] ?? $locale;
        }

        return $options;
    }

    private function brandName(Assessment $assessment): string
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($assessment->brand_id === null || $tenantId === null) {
            return '—';
        }

        try {
            return app(BrandDirectory::class)
                ->resolveConfigurable($tenantId, (string) $assessment->brand_id)
                ->reportDisplayName();
        } catch (\Throwable) {
            return '—';
        }
    }

    /**
     * Separation of duties: true when the acting identity recorded or operates
     * the examination under review and therefore may not approve it.
     */
    private function isSelfApproval(Request $request, Assessment $assessment): bool
    {
        if ((bool) config('lba.reports.allow_self_approval', false)) {
            return false;
        }

        $actorId = (string) $request->user()->getKey();

        if ($assessment->created_by !== null && (string) $assessment->created_by === $actorId) {
            return true;
        }

        if ($assessment->assessed_by !== null && (string) $assessment->assessed_by === $actorId) {
            return true;
        }

        $operatorId = $assessment->examination?->operator_user_id;

        return $operatorId !== null && (string) $operatorId === $actorId;
    }
}
