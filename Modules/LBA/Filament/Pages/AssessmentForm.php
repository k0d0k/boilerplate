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
use Modules\LBA\Exceptions\AssessmentException;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentEvidenceLink;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\BrandDirectory;
use Throwable;

/**
 * Phase 7A — "Form Penilaian LBA", the digital observation assessment.
 *
 * One page, ONE common data model, working across Manual / AI / Hybrid:
 *  - Manual: the operator records observations and categories by hand.
 *  - AI: unreviewed AI suggestions are SHOWN with provenance and must be
 *    verified by a human before anything is recorded — they are never silently
 *    turned into a category or a diagnosis.
 *  - Hybrid: human annotations and AI suggestions coexist as separate evidence
 *    sources and the human reviewer controls the final assessment.
 *
 * The page may submit for review but never approves or publishes a report
 * (Phase 7B). It is reachable only for an examination of an accessible branch
 * of the verified tenant; the underlying assessment is tenant-local and fails
 * closed without that context.
 */
class AssessmentForm extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'lba';

    protected static ?string $slug = 'lba/assessment/{examination}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':lba',
    ];

    protected string $view = 'lba::pages.assessment-form';

    public string $examinationId = '';

    public string $assessmentId = '';

    public string $search = '';

    public string $notes = '';

    public string $programPhase = Assessment::PROGRAM_NOT_SPECIFIED;

    public ?string $brandId = null;

    public ?string $selectedImageId = null;

    /** @var array<string, string> indicator key => recorded category */
    public array $categories = [];

    /** @var array<string, string> indicator key => observation notes */
    public array $itemNotes = [];

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_READ);
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'lba.assessment';
    }

    public function mount(string $examination): void
    {
        $record = Examination::query()->whereKey($examination)->firstOrFail();

        // The assessment is tenant-local; branch scope is enforced here so an
        // identity can never open another branch's work by URL.
        if (! in_array((string) $record->branch_id, static::accessibleBranchIds(), true)) {
            abort(403);
        }

        $actor = auth()->user();
        $actor = $actor instanceof User ? $actor : null;

        $this->examinationId = (string) $record->getKey();
        $this->selectedImageId = $record->images()->value('id');

        $recorder = app(AssessmentRecorder::class);

        // READ-ONLY access must NOT create an assessment and must NOT provision
        // the reference catalog. Only an identity that holds EXAM_UPDATE (module
        // gate + verified tenant/branch permission) may initialize the
        // assessment; a read-only identity only ever loads what already exists.
        if (static::moduleActive() && static::allows(PermissionKey::EXAM_UPDATE)) {
            $this->assessmentId = (string) $recorder->openFor($record, $actor)->getKey();
        } else {
            $this->assessmentId = (string) ($recorder->findFor($record)?->getKey() ?? '');
        }

        $this->hydrate();
    }

    /**
     * Load the assessment state into the Livewire properties.
     */
    private function hydrate(): void
    {
        $assessment = $this->assessment();

        if ($assessment === null) {
            $this->notes = '';
            $this->programPhase = Assessment::PROGRAM_NOT_SPECIFIED;
            $this->brandId = null;
            $this->categories = [];
            $this->itemNotes = [];

            return;
        }

        $this->notes = (string) ($assessment->notes ?? '');
        $this->programPhase = (string) $assessment->program_phase;
        $this->brandId = $assessment->brand_id !== null ? (string) $assessment->brand_id : null;

        $this->categories = [];
        $this->itemNotes = [];

        foreach ($assessment->items()->get() as $item) {
            $this->categories[(string) $item->indicator_key] = (string) $item->category;
            $this->itemNotes[(string) $item->indicator_key] = (string) ($item->notes ?? '');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.assessment.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.assessment.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.assessment.subtitle');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('lba.assessment.back'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->url(fn (): string => ExaminationResource::getUrl('view', ['record' => $this->examination()])),
        ];
    }

    public function examination(): Examination
    {
        return Examination::query()->whereKey($this->examinationId)->firstOrFail();
    }

    /**
     * The assessment of this examination, or null when none exists yet (a
     * read-only identity viewing an examination that has never been assessed).
     * Never creates one.
     */
    public function assessment(): ?Assessment
    {
        if ($this->assessmentId === '') {
            return null;
        }

        try {
            return Assessment::query()->whereKey($this->assessmentId)->first();
        } catch (Throwable) {
            return null;
        }
    }

    public function hasAssessment(): bool
    {
        return $this->assessment() !== null;
    }

    /**
     * @return Collection<int, AssessmentItem>
     */
    public function items(): Collection
    {
        $assessment = $this->assessment();

        return $assessment !== null ? $assessment->items()->get() : collect();
    }

    /**
     * @return Collection<int, AssessmentItem>
     */
    public function filteredItems(): Collection
    {
        $items = $this->items();

        $search = trim(mb_strtolower($this->search));

        if ($search === '') {
            return $items;
        }

        return $items->filter(function (AssessmentItem $item) use ($search): bool {
            return str_contains(mb_strtolower($item->displayLabel()), $search)
                || str_contains(mb_strtolower((string) $item->indicator_key), $search);
        })->values();
    }

    /**
     * @return Collection<int, ExaminationImage>
     */
    public function images(): Collection
    {
        return $this->examination()->images()->get();
    }

    /**
     * Unreviewed AI SUGGESTIONS for the whole examination. Shown as information
     * only — never a category, never a diagnosis.
     *
     * @return Collection<int, Detection>
     */
    public function aiSuggestions(): Collection
    {
        return Detection::query()
            ->where('examination_id', $this->examinationId)
            ->orderByDesc('confidence')
            ->limit(20)
            ->get();
    }

    public function modeLabel(): string
    {
        $mode = $this->assessment()?->mode ?? $this->examination()->mode;

        return match ((string) $mode) {
            Examination::MODE_AI => __('lba.exams.mode_ai'),
            Examination::MODE_HYBRID => __('lba.exams.mode_hybrid'),
            default => __('lba.exams.mode_manual'),
        };
    }

    public function patientName(): string
    {
        return (string) ($this->examination()->patient?->full_name ?? '—');
    }

    public function branchName(): string
    {
        return (string) ($this->examination()->branch?->name ?? '—');
    }

    public function operatorName(): ?string
    {
        $operatorId = $this->examination()->operator_user_id;

        if ($operatorId === null) {
            return null;
        }

        return User::query()->whereKey($operatorId)->value('name');
    }

    public function lastSavedAt(): ?string
    {
        return $this->assessment()?->last_saved_at?->format('Y-m-d H:i');
    }

    /**
     * @return array{green: int, yellow: int, red: int}
     */
    public function selectedCounts(): array
    {
        return [
            AssessmentItem::CATEGORY_GREEN => $this->items()->where('category', AssessmentItem::CATEGORY_GREEN)->count(),
            AssessmentItem::CATEGORY_YELLOW => $this->items()->where('category', AssessmentItem::CATEGORY_YELLOW)->count(),
            AssessmentItem::CATEGORY_RED => $this->items()->where('category', AssessmentItem::CATEGORY_RED)->count(),
        ];
    }

    public function unassessedCount(): int
    {
        return $this->items()->where('category', AssessmentItem::CATEGORY_UNASSESSED)->count();
    }

    /**
     * @return array<string, int> indicator key => number of attached evidence links
     */
    public function evidenceCounts(): array
    {
        return AssessmentEvidenceLink::query()
            ->where('assessment_id', $this->assessmentId)
            ->get()
            ->groupBy('indicator_key')
            ->map(fn (Collection $group): int => $group->count())
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function programPhaseOptions(): array
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
    public function brandOptions(): array
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return [];
        }

        return app(BrandDirectory::class)->brandOptions($tenantId);
    }

    public function tenantId(): ?string
    {
        $user = static::currentUser();

        if ($user === null) {
            return null;
        }

        return app(ManagesTenantContext::class)->currentTenantId($user);
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

    public function canEdit(): bool
    {
        $assessment = $this->assessment();

        return $assessment !== null
            && static::moduleActive()
            && static::allows(PermissionKey::EXAM_UPDATE)
            && $assessment->isEditable();
    }

    public function canSubmitForReview(): bool
    {
        $assessment = $this->assessment();

        return $assessment !== null
            && $this->canEdit()
            && $assessment->canTransitionTo(Assessment::STATUS_SUBMITTED_FOR_REVIEW);
    }

    /**
     * Re-derive EVERY authorization fact for a mutation. This is the Livewire
     * security boundary: it revalidates the acting user, the module gate, the
     * EXAM_UPDATE permission under the *current* verified tenant/branch context,
     * and (implicitly, via {@see Assessment()}) that the assessment still exists
     * in the bound tenant. Any tenant switch, branch switch or membership
     * revocation between render and request fails closed here.
     */
    private function canMutate(): bool
    {
        $user = static::currentUser();

        if ($user === null || ! static::moduleActive() || ! static::allows(PermissionKey::EXAM_UPDATE)) {
            return false;
        }

        $record = $this->resolveExamination();

        if ($record === null) {
            return false;
        }

        // The examination must still be in the *currently verified* branch of
        // the actor — not merely a branch they could access in some other
        // context. Switching branch (or losing the assignment) invalidates it.
        $currentBranch = app(ManagesBranchContext::class)->currentBranchId($user);

        if ($currentBranch === null || (string) $record->branch_id !== $currentBranch) {
            return false;
        }

        return $this->assessment() !== null;
    }

    /**
     * Resolve the examination without throwing when the tenant/branch context
     * changed and the row is no longer reachable.
     */
    private function resolveExamination(): ?Examination
    {
        try {
            return Examination::query()->whereKey($this->examinationId)->first();
        } catch (Throwable) {
            return null;
        }
    }

    public function selectImage(string $imageId): void
    {
        if (! $this->images()->contains(fn (ExaminationImage $image): bool => (string) $image->getKey() === $imageId)) {
            return;
        }

        $this->selectedImageId = $imageId;
    }

    public function setCategory(string $indicatorKey, string $category): void
    {
        if (! $this->canMutate()) {
            $this->notifyUnauthorized();

            return;
        }

        try {
            app(AssessmentRecorder::class)->setCategory(
                $this->assessment(),
                $indicatorKey,
                $category,
                $this->actor(),
            );

            $this->categories[$indicatorKey] = $category;
        } catch (AssessmentException $exception) {
            $this->notifyError($exception);
        }
    }

    public function saveItemNotes(string $indicatorKey): void
    {
        if (! $this->canMutate()) {
            $this->notifyUnauthorized();

            return;
        }

        $notes = $this->itemNotes[$indicatorKey] ?? '';
        $notes = trim((string) $notes);

        try {
            app(AssessmentRecorder::class)->updateNotes(
                $this->assessment(),
                $indicatorKey,
                $notes === '' ? null : $notes,
                $this->actor(),
            );

            $this->notifySuccess(__('lba.assessment.notes_saved'));
        } catch (AssessmentException $exception) {
            $this->notifyError($exception);
        }
    }

    /**
     * Attach the currently selected microscope image as supporting evidence for
     * one indicator. The image must belong to this examination.
     */
    public function attachImage(string $indicatorKey): void
    {
        if (! $this->canMutate()) {
            $this->notifyUnauthorized();

            return;
        }

        if ($this->selectedImageId === null) {
            $this->notifyError(new AssessmentException(__('lba.assessment.select_image_first')));

            return;
        }

        try {
            app(AssessmentRecorder::class)->attachEvidence(
                $this->assessment(),
                $indicatorKey,
                AssessmentEvidenceLink::TYPE_IMAGE,
                $this->selectedImageId,
                $this->actor(),
            );

            $this->notifySuccess(__('lba.assessment.evidence_attached'));
        } catch (AssessmentException $exception) {
            $this->notifyError($exception);
        }
    }

    public function updatedProgramPhase(string $value): void
    {
        if (! $this->canMutate()) {
            $this->notifyUnauthorized();
            $this->hydrate();

            return;
        }

        try {
            app(AssessmentRecorder::class)->setProgramPhase($this->assessment(), $value, $this->actor());
        } catch (AssessmentException $exception) {
            $this->notifyError($exception);
            $this->hydrate();
        }
    }

    public function updatedBrandId(?string $value): void
    {
        $tenantId = $this->tenantId();

        if (! $this->canMutate() || $tenantId === null || $value === null || $value === '') {
            return;
        }

        try {
            app(AssessmentRecorder::class)->selectBrand($this->assessment(), $tenantId, $value, $this->actor());
        } catch (AssessmentException $exception) {
            $this->notifyError($exception);
            $this->hydrate();
        }
    }

    public function saveDraft(): void
    {
        if (! $this->canMutate()) {
            $this->notifyUnauthorized();

            return;
        }

        try {
            // Summary-notes persistence lives in the recorder so it runs inside
            // the SAME verified tenant transaction that stamps the draft.
            app(AssessmentRecorder::class)->saveSummaryNotes(
                $this->assessment(),
                $this->notes !== '' ? $this->notes : null,
                $this->actor(),
            );

            $this->notifySuccess(__('lba.assessment.draft_saved'));
        } catch (AssessmentException $exception) {
            $this->notifyError($exception);
        }
    }

    public function submitForReview(): void
    {
        if (! $this->canMutate() || ! $this->canSubmitForReview()) {
            $this->notifyUnauthorized();

            return;
        }

        try {
            app(AssessmentRecorder::class)->submitForReview($this->assessment(), $this->actor());

            $this->notifySuccess(__('lba.assessment.submitted'));
            $this->hydrate();
        } catch (AssessmentException $exception) {
            $this->notifyError($exception);
        }
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function notifySuccess(string $message): void
    {
        Notification::make()->success()->title($message)->send();
    }

    private function notifyError(AssessmentException $exception): void
    {
        Notification::make()->danger()->title($exception->getMessage())->send();
    }

    private function notifyUnauthorized(): void
    {
        Notification::make()
            ->danger()
            ->title(__('lba.assessment.not_authorized'))
            ->send();
    }
}
