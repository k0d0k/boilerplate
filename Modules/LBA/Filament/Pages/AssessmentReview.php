<?php

namespace Modules\LBA\Filament\Pages;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\LBA\Exceptions\ReviewException;
use Modules\LBA\Models\Annotation;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Models\ReportEvent;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\BrandDirectory;
use Modules\LBA\Services\ClinicalTerminologyGate;
use Throwable;

/**
 * Phase 7B — "Tinjauan Penilai", the ONE page where a human reviewer decides the
 * fate of a submitted assessment.
 *
 * It surfaces, side by side and NEVER conflated: the original microscope images,
 * the HUMAN annotations, the unreviewed AI SUGGESTIONS, the observation
 * categories with their supporting evidence and the terminology status. The
 * reviewer may return the work for changes, reject it, or approve it — and
 * approval is the only action that issues a patient-facing PDF report.
 *
 * Nothing here approves automatically and no AI process can call it: every
 * action re-derives the acting identity, the module gate, the verified
 * tenant/branch context and the required permission on the server. A reviewer
 * may not approve work they recorded or operate (separation of duties).
 */
class AssessmentReview extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'lba';

    protected static ?string $slug = 'lba/reviews/{assessment}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':lba',
    ];

    protected string $view = 'lba::pages.assessment-review';

    public string $assessmentId = '';

    public string $notes = '';

    public ?string $locale = null;

    public ?string $lastReportId = null;

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_REVIEW);
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'lba.review';
    }

    public function mount(string $assessment): void
    {
        $record = $this->resolveAssessment($assessment);

        abort_if($record === null, 404);

        $examination = $record->examination;

        // Branch scope: an identity may never open another branch's review by
        // URL, even inside the same tenant.
        abort_if($examination === null, 404);
        abort_unless(in_array((string) $examination->branch_id, static::accessibleBranchIds(), true), 403);

        $this->assessmentId = (string) $record->getKey();
        $this->notes = '';
        $this->locale = null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.review.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.review.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.review.subtitle');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('lba.review.back'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->url(fn (): string => ReviewerInbox::getUrl()),
        ];
    }

    // --------------------------------------------------------------- Resolution

    public function assessment(): ?Assessment
    {
        return $this->resolveAssessment($this->assessmentId);
    }

    private function resolveAssessment(string $id): ?Assessment
    {
        if ($id === '') {
            return null;
        }

        try {
            $record = Assessment::query()->whereKey($id)->with(['examination.patient', 'examination.branch'])->first();
        } catch (Throwable) {
            return null;
        }

        return $record;
    }

    public function examination(): ?Examination
    {
        return $this->assessment()?->examination;
    }

    public function patientName(): string
    {
        return (string) ($this->examination()?->patient?->full_name ?? '—');
    }

    public function branchName(): string
    {
        return (string) ($this->examination()?->branch?->name ?? '—');
    }

    public function modeLabel(): string
    {
        return match ((string) ($this->examination()?->mode)) {
            Examination::MODE_AI => __('lba.exams.mode_ai'),
            Examination::MODE_HYBRID => __('lba.exams.mode_hybrid'),
            default => __('lba.exams.mode_manual'),
        };
    }

    public function statusLabel(): string
    {
        $assessment = $this->assessment();

        return $assessment !== null ? __('lba.assessment.status_'.$assessment->status) : '—';
    }

    // --------------------------------------------------------------- Review data

    /**
     * @return Collection<int, ExaminationImage>
     */
    public function images(): Collection
    {
        return $this->examination()?->images()->get() ?? collect();
    }

    /**
     * The HUMAN annotations of the examination (job_id is null / human origin).
     * Shown separately from AI suggestions, never merged with them.
     *
     * @return Collection<int, Annotation>
     */
    public function humanAnnotations(): Collection
    {
        $examination = $this->examination();

        if ($examination === null) {
            return collect();
        }

        return Annotation::query()
            ->where('examination_id', $examination->getKey())
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The unreviewed AI SUGGESTIONS of the examination. Information only — never
     * a clinical finding and never auto-accepted.
     *
     * @return Collection<int, Detection>
     */
    public function aiSuggestions(): Collection
    {
        $examination = $this->examination();

        if ($examination === null) {
            return collect();
        }

        return Detection::query()
            ->where('examination_id', $examination->getKey())
            ->orderByDesc('confidence')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, AssessmentItem>
     */
    public function items(): Collection
    {
        return $this->assessment()?->items()->get() ?? collect();
    }

    /**
     * @return array<string, list<array{type: string, provenance: string}>>
     */
    public function evidenceByIndicator(): array
    {
        $assessment = $this->assessment();

        if ($assessment === null) {
            return [];
        }

        $grouped = [];

        foreach ($assessment->evidenceLinks()->get() as $link) {
            $grouped[(string) $link->indicator_key][] = [
                'type' => (string) $link->evidence_type,
                'provenance' => (string) $link->provenance,
            ];
        }

        return $grouped;
    }

    public function categoryLabel(string $category): string
    {
        return match ($category) {
            AssessmentItem::CATEGORY_GREEN => __('lba.assessment.category_green'),
            AssessmentItem::CATEGORY_YELLOW => __('lba.assessment.category_yellow'),
            AssessmentItem::CATEGORY_RED => __('lba.assessment.category_red'),
            default => __('lba.assessment.category_unassessed'),
        };
    }

    /**
     * The terminology gate's verdict for the reviewer: how many indicators carry
     * reviewed, patient-facing terminology versus reference-only labels.
     */
    public function terminologySummary(): array
    {
        $assessment = $this->assessment();
        $gate = app(ClinicalTerminologyGate::class);

        return [
            'reviewed' => $assessment !== null ? count($gate->clinicalFindings($assessment)) : 0,
            'reference_only' => $assessment !== null ? $gate->pendingTerminologyCount($assessment) : 0,
        ];
    }

    // --------------------------------------------------------------- Permissions

    public function canDecide(): bool
    {
        $assessment = $this->assessment();

        return $assessment !== null
            && $this->contextValid()
            && static::allows(PermissionKey::EXAM_REVIEW)
            && $assessment->status === Assessment::STATUS_SUBMITTED_FOR_REVIEW;
    }

    public function canApprove(): bool
    {
        return $this->canDecide()
            && static::allows(PermissionKey::REPORT_APPROVE)
            && ! $this->isSelfApproval()
            && $this->assessment()?->brand_id !== null;
    }

    /**
     * True when the acting identity recorded or operates the examination under
     * review — they must not approve it (separation of duties).
     */
    public function isSelfApproval(): bool
    {
        if ((bool) config('lba.reports.allow_self_approval', false)) {
            return false;
        }

        $assessment = $this->assessment();
        $actor = $this->actor();

        if ($assessment === null || $actor === null) {
            return false;
        }

        $actorId = (string) $actor->getKey();

        if ($assessment->created_by !== null && (string) $assessment->created_by === $actorId) {
            return true;
        }

        if ($assessment->assessed_by !== null && (string) $assessment->assessed_by === $actorId) {
            return true;
        }

        $operatorId = $this->examination()?->operator_user_id;

        return $operatorId !== null && (string) $operatorId === $actorId;
    }

    public function canGenerate(): bool
    {
        $assessment = $this->assessment();

        return $assessment !== null
            && $this->contextValid()
            && static::allows(PermissionKey::REPORT_APPROVE)
            && $assessment->status === Assessment::STATUS_REVIEWED
            && $assessment->brand_id !== null;
    }

    /**
     * Release is available only once the assessment is approved, the reviewer
     * holds `report.approve` in the verified context, and the version has not
     * already been released (release is immutable and idempotent).
     */
    public function canRelease(ReportVersion $version): bool
    {
        $assessment = $this->assessment();

        return $assessment !== null
            && $this->contextValid()
            && static::allows(PermissionKey::REPORT_APPROVE)
            && $assessment->status === Assessment::STATUS_REVIEWED
            && ! $version->isReleased();
    }

    /**
     * The Livewire security boundary: re-derive every authorization fact for a
     * mutation against the *current* verified tenant/branch context.
     */
    private function contextValid(): bool
    {
        $user = static::currentUser();

        if ($user === null || ! static::moduleActive()) {
            return false;
        }

        $examination = $this->examination();

        if ($examination === null) {
            return false;
        }

        $branch = app(ManagesBranchContext::class)->currentBranchId($user);

        return $branch !== null && (string) $examination->branch_id === $branch;
    }

    // --------------------------------------------------------------- Actions

    public function returnForChanges(): void
    {
        $assessment = $this->assessment();
        $actor = $this->actor();

        if ($assessment === null || $actor === null || ! $this->canDecide()) {
            $this->notifyUnauthorized();

            return;
        }

        try {
            app(AssessmentReviewer::class)->returnForChanges($assessment, $actor, $this->notesOrNull());

            Notification::make()->success()->title(__('lba.review.returned'))->send();
            $this->notes = '';
        } catch (ReviewException $exception) {
            $this->notifyError($exception);
        }
    }

    public function reject(): void
    {
        $assessment = $this->assessment();
        $actor = $this->actor();

        if ($assessment === null || $actor === null || ! $this->canDecide()) {
            $this->notifyUnauthorized();

            return;
        }

        try {
            app(AssessmentReviewer::class)->reject($assessment, $actor, $this->notesOrNull());

            Notification::make()->success()->title(__('lba.review.rejected'))->send();
            $this->notes = '';
        } catch (ReviewException $exception) {
            $this->notifyError($exception);
        }
    }

    /**
     * Explicitly approve the assessment and issue its FIRST immutable report
     * version. This is the only path to a patient-facing report.
     */
    public function approve(): void
    {
        $assessment = $this->assessment();
        $actor = $this->actor();
        $tenantId = $this->tenantId();

        if ($assessment === null || $actor === null || $tenantId === null || ! $this->canApprove()) {
            $this->notifyUnauthorized();

            return;
        }

        try {
            $version = app(AssessmentReviewer::class)->approve(
                $assessment,
                $tenantId,
                $actor,
                $this->notesOrNull(),
                $this->localeOrNull(),
            );

            $this->lastReportId = (string) $version->getKey();
            $this->notes = '';

            Notification::make()->success()->title(__('lba.review.approved'))->send();
        } catch (ReviewException $exception) {
            $this->notifyError($exception);
        }
    }

    /**
     * Issue a NEW immutable report version for an already-approved assessment
     * (a revision never overwrites an existing version).
     */
    public function generateReport(): void
    {
        $assessment = $this->assessment();
        $actor = $this->actor();
        $tenantId = $this->tenantId();

        if ($assessment === null || $actor === null || $tenantId === null || ! $this->canGenerate()) {
            $this->notifyUnauthorized();

            return;
        }

        try {
            $version = app(AssessmentReviewer::class)->generateReport(
                $assessment,
                $tenantId,
                $actor,
                $this->localeOrNull(),
            );

            $this->lastReportId = (string) $version->getKey();

            Notification::make()->success()->title(__('lba.review.generated'))->send();
        } catch (ReviewException $exception) {
            $this->notifyError($exception);
        }
    }

    /**
     * Explicitly release ONE report version to the patient portal.
     *
     * Approval never publishes by itself: a reviewer must re-confirm the acting
     * identity, the module gate, the verified branch context and `report.approve`
     * on the server before a specific version becomes patient-facing. Cross-
     * assessment ids fail closed (the version is resolved through this
     * assessment's own versions only).
     */
    public function releaseToPatient(string $versionId): void
    {
        $assessment = $this->assessment();
        $actor = $this->actor();
        $tenantId = $this->tenantId();

        if ($assessment === null || $actor === null || $tenantId === null || ! $this->contextValid()
            || ! static::allows(PermissionKey::REPORT_APPROVE)) {
            $this->notifyUnauthorized();

            return;
        }

        $version = $assessment->reportVersions()->whereKey($versionId)->first();

        if ($version === null) {
            $this->notifyUnauthorized();

            return;
        }

        try {
            app(AssessmentReviewer::class)->releaseToPatient($version, $tenantId, $actor, $this->notesOrNull());

            $this->notes = '';

            Notification::make()->success()->title(__('lba.review.released'))->send();
        } catch (ReviewException $exception) {
            $this->notifyError($exception);
        }
    }

    // --------------------------------------------------------------- Report data

    /**
     * @return Collection<int, ReportVersion>
     */
    public function reportVersions(): Collection
    {
        return $this->assessment()?->reportVersions()->get() ?? collect();
    }

    public function latestReport(): ?ReportVersion
    {
        return $this->reportVersions()->first();
    }

    /**
     * @return Collection<int, ReportEvent>
     */
    public function reportHistory(): Collection
    {
        $assessment = $this->assessment();

        if ($assessment === null) {
            return collect();
        }

        return ReportEvent::query()
            ->where('assessment_id', $assessment->getKey())
            ->orderBy('occurred_at')
            ->get();
    }

    public function previewUrl(ReportVersion $version): string
    {
        return route('lba.reports.preview', ['report' => $version->getKey()]);
    }

    public function downloadUrl(ReportVersion $version): string
    {
        return route('lba.reports.download', ['report' => $version->getKey()]);
    }

    public function reportEventLabel(string $action): string
    {
        return __('lba.review.event_'.$action);
    }

    public function availableLocales(): array
    {
        $labels = (array) config('localization.labels', []);

        $options = ['__brand__' => __('lba.review.locale_brand_default')];

        foreach ((array) config('lba.reports.locales', ['id', 'ms-MY', 'en']) as $locale) {
            $options[$locale] = $labels[$locale] ?? $locale;
        }

        return $options;
    }

    public function brandName(): string
    {
        $assessment = $this->assessment();
        $tenantId = $this->tenantId();

        if ($assessment?->brand_id === null || $tenantId === null) {
            return '—';
        }

        try {
            return app(BrandDirectory::class)->resolveConfigurable($tenantId, (string) $assessment->brand_id)->reportDisplayName();
        } catch (Throwable) {
            return '—';
        }
    }

    public function hasBrand(): bool
    {
        return $this->assessment()?->brand_id !== null;
    }

    // --------------------------------------------------------------- Support

    public function tenantId(): ?string
    {
        $user = static::currentUser();

        if ($user === null) {
            return null;
        }

        return app(ManagesTenantContext::class)->currentTenantId($user);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active ? $user : null;
    }

    private function notesOrNull(): ?string
    {
        $notes = trim($this->notes);

        return $notes === '' ? null : $notes;
    }

    private function localeOrNull(): ?string
    {
        $locale = $this->locale;

        return $locale === null || $locale === '' || $locale === '__brand__' ? null : $locale;
    }

    private function notifyError(ReviewException $exception): void
    {
        Notification::make()->danger()->title($exception->getMessage())->send();
    }

    private function notifyUnauthorized(): void
    {
        Notification::make()->danger()->title(__('lba.review.not_authorized'))->send();
    }
}
