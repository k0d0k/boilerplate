<?php

namespace Modules\LBA\Filament\Pages;

use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Livewire\WithFileUploads;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\AI\Services\AiAnalysisService;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Annotation;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\AnnotationRecorder;
use Modules\LBA\Services\ExaminationWorkflow;
use Modules\LBA\Services\MicroscopyImageStore;

/**
 * Phase 4 — the dark microscope workspace (Modules/LBA).
 *
 * A single examination's images are inspected and annotated MANUALLY here. It
 * needs no AI server: annotations are authored by a human, stored with
 * `job_id = null`, and their geometry is NORMALISED against the original image
 * (0..1) so zooming or panning never changes a stored position.
 *
 * The workspace can only reach `review_required` — there is deliberately no
 * approve/publish path (human review + publication are Phase 7).
 *
 * @property-read Examination $examination
 */
class MicroscopeWorkspace extends Page
{
    use AuthorizesModuleResources;
    use WithFileUploads;

    public const MODULE_KEY = 'lba';

    protected static ?string $slug = 'lba/workspace/{examination}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':lba',
    ];

    protected string $view = 'lba::pages.microscope-workspace';

    public string $examinationId = '';

    public ?string $selectedImageId = null;

    public string $tool = 'select';

    public ?string $selectedAnnotationId = null;

    public string $label = '';

    public string $notes = '';

    /**
     * Phase 5 — AI suggestion overlay controls. AI suggestions are shown as
     * UNREVIEWED suggestions, visually distinct from human annotations.
     */
    public bool $aiOverlayVisible = true;

    public string $aiLabelFilter = '';

    public float $aiMinConfidence = 0.0;

    /**
     * WS6.A — a still captured in the browser, held as an authenticated Livewire
     * temporary upload until {@see self::storeCapturedImage()} runs it through the
     * exact same validated, private intake path as a manual import. Untyped on
     * purpose: Livewire swaps the value between null and a TemporaryUploadedFile.
     */
    public $capturedImage = null;

    protected ?Examination $examinationCache = null;

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
        // A stable, brace-free route name; the `{examination}` segment stays in
        // the URL only.
        return 'lba.workspace';
    }

    public function mount(string $examination): void
    {
        $record = Examination::query()->whereKey($examination)->firstOrFail();

        // The record itself is tenant-scoped; branch scope is enforced here so
        // an identity can never open another branch's work by URL.
        if (! in_array((string) $record->branch_id, static::accessibleBranchIds(), true)) {
            abort(403);
        }

        $this->examinationId = (string) $record->getKey();
        $this->selectedImageId = $record->images()->value('id');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.exams.workspace.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('assessment')
                ->label(__('lba.assessment.nav'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('warning')
                ->url(fn (): string => AssessmentForm::getUrl(['examination' => $this->examinationId])),
        ];
    }

    public function examination(): Examination
    {
        return $this->examinationCache ??= Examination::query()
            ->whereKey($this->examinationId)
            ->firstOrFail();
    }

    /**
     * @return Collection<int, ExaminationImage>
     */
    public function images(): Collection
    {
        return $this->examination()->images()->get();
    }

    public function selectedImage(): ?ExaminationImage
    {
        if ($this->selectedImageId === null) {
            return null;
        }

        return $this->images()->firstWhere('id', $this->selectedImageId);
    }

    /**
     * @return Collection<int, Annotation>
     */
    public function annotations(): Collection
    {
        $image = $this->selectedImage();

        if ($image === null) {
            return collect();
        }

        return $image->annotations()->orderBy('created_at')->get();
    }

    public function selectedAnnotation(): ?Annotation
    {
        if ($this->selectedAnnotationId === null) {
            return null;
        }

        return $this->annotations()->firstWhere('id', $this->selectedAnnotationId);
    }

    /**
     * Whether AI processing is available in this environment at all.
     */
    public function aiEnabled(): bool
    {
        return app(AiAnalysisService::class)->aiEnabled();
    }

    /**
     * Whether the acting identity may start an AI analysis for the selected
     * image right now (module + permission + mode + consent + editable + enabled).
     */
    public function canStartAnalysis(): bool
    {
        $image = $this->selectedImage();

        return $image !== null
            && static::moduleActive()
            && static::allows(PermissionKey::EXAM_AI_START)
            && $this->examination()->isAiCapable()
            && $this->examination()->isEditable()
            && $this->examination()->hasGrantedImageProcessingConsent()
            && $this->aiEnabled();
    }

    /**
     * Unreviewed AI SUGGESTIONS for the selected image, filtered by label and
     * minimum confidence. These are suggestions only — never clinical findings.
     *
     * @return Collection<int, Detection>
     */
    public function detections(): Collection
    {
        $image = $this->selectedImage();

        if ($image === null) {
            return collect();
        }

        return Detection::query()
            ->where('image_id', $image->getKey())
            ->when($this->aiLabelFilter !== '', fn ($query) => $query->where('label', $this->aiLabelFilter))
            ->when($this->aiMinConfidence > 0.0, fn ($query) => $query->where('confidence', '>=', $this->aiMinConfidence))
            ->orderByDesc('confidence')
            ->get();
    }

    /**
     * Distinct labels of the selected image's suggestions, for the filter.
     *
     * @return array<string, string>
     */
    public function aiLabelOptions(): array
    {
        $image = $this->selectedImage();

        if ($image === null) {
            return [];
        }

        $labels = Detection::query()
            ->where('image_id', $image->getKey())
            ->orderBy('label')
            ->pluck('label')
            ->unique()
            ->all();

        $options = [];

        foreach ($labels as $label) {
            $options[(string) $label] = (string) $label;
        }

        return $options;
    }

    /**
     * Analysis jobs for the selected image, newest first.
     *
     * @return Collection<int, AnalysisJob>
     */
    public function analysisJobs(): Collection
    {
        $image = $this->selectedImage();

        if ($image === null) {
            return collect();
        }

        return AnalysisJob::query()
            ->where('image_id', $image->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    public function latestJob(): ?AnalysisJob
    {
        return $this->analysisJobs()->first();
    }

    /**
     * Start an idempotent AI analysis for the selected image.
     */
    public function startAnalysis(): void
    {
        $image = $this->selectedImage();

        if ($image === null || ! $this->canStartAnalysis()) {
            Notification::make()
                ->danger()
                ->title(__('lba.exams.ai.unavailable'))
                ->send();

            return;
        }

        $actor = auth()->user();

        try {
            app(AiAnalysisService::class)->startAnalysis(
                $this->examination(),
                $image,
                $actor instanceof User ? $actor : null,
            );

            Notification::make()
                ->success()
                ->title(__('lba.exams.ai.queued'))
                ->send();
        } catch (AiAnalysisException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();
        }
    }

    /**
     * Re-queue a failed analysis job (explicit, authorized and idempotent).
     */
    public function retryAnalysis(string $jobId): void
    {
        if (! static::moduleActive() || ! static::allows(PermissionKey::EXAM_AI_START)) {
            Notification::make()
                ->danger()
                ->title(__('lba.exams.ai.unavailable'))
                ->send();

            return;
        }

        $job = $this->analysisJobs()->firstWhere('id', $jobId);

        if ($job === null) {
            return;
        }

        $actor = auth()->user();

        try {
            app(AiAnalysisService::class)->retryAnalysis($job, $actor instanceof User ? $actor : null);

            Notification::make()
                ->success()
                ->title(__('lba.exams.ai.retry_queued'))
                ->send();
        } catch (AiAnalysisException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();
        }
    }

    public function toggleAiOverlay(): void
    {
        $this->aiOverlayVisible = ! $this->aiOverlayVisible;
    }

    /**
     * Whether the acting identity may author / change manual annotations now.
     */
    public function canAnnotate(): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::EXAM_UPDATE)
            && static::allows(PermissionKey::ANNOTATION_CREATE)
            && $this->examination()->isEditable();
    }

    /**
     * Whether the examination may be submitted for expert review.
     */
    public function canSubmitForReview(): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::EXAM_UPDATE)
            && $this->examination()->canTransitionTo(Examination::STATUS_REVIEW_REQUIRED);
    }

    // ------------------------------------------------- WS6.A — browser capture

    /**
     * WS6.A — whether the acting identity may capture and store a still right
     * now. Mirrors the intake gates enforced by {@see MicroscopyImageStore} so
     * the UI can offer capture only when the store would accept it; the store
     * re-checks every gate server-side, so a forged request cannot bypass them.
     */
    public function canCapture(): bool
    {
        $examination = $this->examination();

        return static::moduleActive()
            && static::allows(PermissionKey::EXAM_UPDATE)
            && $examination->isEditable()
            && in_array($examination->mode, Examination::PROCESSABLE_MODES, true)
            && $examination->hasGrantedImageProcessingConsent();
    }

    /**
     * The configured per-file size ceiling, shown to the operator and enforced
     * by the store. Never loosens the server-side limit.
     */
    public function captureUploadLimitKb(): int
    {
        return (int) config('lba.images.max_kilobytes');
    }

    /**
     * Persist a still captured in the browser.
     *
     * The frame arrives through Livewire's authenticated, CSRF-protected
     * temporary upload; this action then runs it through the SAME validation and
     * private, immutable write path as a manual import. No camera frame is ever
     * written to a public disk, broadcast or stored before authorization.
     *
     * `$instrumentLabel` is the operator's selected capture device label. It is
     * untrusted display metadata only — it never selects a tenant, branch,
     * patient or examination, and the store bounds/normalises it.
     */
    public function storeCapturedImage(?string $instrumentLabel = null): void
    {
        if (! $this->canCapture()) {
            $this->capturedImage = null;

            Notification::make()
                ->danger()
                ->title(__('lba.exams.workspace.capture_unavailable'))
                ->send();

            return;
        }

        $file = $this->capturedImage;

        if (! $file instanceof UploadedFile) {
            return;
        }

        $actor = auth()->user();

        try {
            $image = app(MicroscopyImageStore::class)->store(
                $this->examination(),
                $file,
                $actor instanceof User ? $actor : null,
                [
                    'source' => ExaminationImage::SOURCE_BROWSER_CAPTURE,
                    'captured_at' => now(),
                    'instrument_label' => $instrumentLabel,
                ],
            );
        } catch (ExaminationException $exception) {
            $this->capturedImage = null;

            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();

            return;
        }

        $this->capturedImage = null;
        $this->selectedImageId = (string) $image->getKey();
        $this->selectedAnnotationId = null;
        $this->label = '';
        $this->notes = '';

        Notification::make()
            ->success()
            ->title(__('lba.exams.workspace.capture_stored'))
            ->send();
    }

    public function discardCapturedImage(): void
    {
        $this->capturedImage = null;
    }

    public function selectImage(string $imageId): void
    {
        if (! $this->images()->contains(fn (ExaminationImage $image): bool => (string) $image->getKey() === $imageId)) {
            return;
        }

        $this->selectedImageId = $imageId;
        $this->selectedAnnotationId = null;
        $this->label = '';
        $this->notes = '';
    }

    public function setTool(string $tool): void
    {
        if (! in_array($tool, ['select', ...Annotation::TYPES], true)) {
            return;
        }

        $this->tool = $tool;
    }

    /**
     * Persist a manual annotation drawn in the browser.
     *
     * The geometry arrives NORMALISED (0..1) against the original image; the
     * recorder re-validates and clamps it. `job_id` stays null — no AI job.
     *
     * @param  array<string, mixed>  $geometry
     */
    public function createAnnotation(string $type, array $geometry): void
    {
        if (! $this->canAnnotate()) {
            Notification::make()
                ->danger()
                ->title(__('lba.exams.workspace.not_editable'))
                ->send();

            return;
        }

        $image = $this->selectedImage();

        if ($image === null) {
            return;
        }

        $actor = auth()->user();

        try {
            $annotation = app(AnnotationRecorder::class)->create($this->examination(), $image, [
                'type' => $type,
                'geometry' => $geometry,
                'label' => $this->label !== '' ? $this->label : null,
                'notes' => $this->notes !== '' ? $this->notes : null,
            ], $actor instanceof User ? $actor : null);

            $this->selectedAnnotationId = (string) $annotation->getKey();
            $this->label = '';
            $this->notes = '';

            Notification::make()
                ->success()
                ->title(__('lba.exams.workspace.annotation_saved'))
                ->send();
        } catch (ExaminationException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();
        }
    }

    public function selectAnnotation(string $annotationId): void
    {
        $annotation = $this->annotations()->firstWhere('id', $annotationId);

        if ($annotation === null) {
            return;
        }

        $this->selectedAnnotationId = (string) $annotation->getKey();
        $this->label = (string) ($annotation->label ?? '');
        $this->notes = (string) ($annotation->notes ?? '');
    }

    /**
     * Update the currently selected manual annotation's label / notes.
     */
    public function saveAnnotation(): void
    {
        $annotation = $this->selectedAnnotation();

        if ($annotation === null) {
            return;
        }

        if (! $this->canAnnotate()) {
            Notification::make()
                ->danger()
                ->title(__('lba.exams.workspace.not_editable'))
                ->send();

            return;
        }

        $actor = auth()->user();

        try {
            app(AnnotationRecorder::class)->update($annotation, [
                'label' => $this->label !== '' ? $this->label : null,
                'notes' => $this->notes !== '' ? $this->notes : null,
            ], $actor instanceof User ? $actor : null);

            Notification::make()
                ->success()
                ->title(__('lba.exams.workspace.annotation_updated'))
                ->send();
        } catch (ExaminationException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();
        }
    }

    public function deleteAnnotation(string $annotationId): void
    {
        $annotation = $this->annotations()->firstWhere('id', $annotationId);

        if ($annotation === null) {
            return;
        }

        if (! $this->canAnnotate()) {
            Notification::make()
                ->danger()
                ->title(__('lba.exams.workspace.not_editable'))
                ->send();

            return;
        }

        try {
            app(AnnotationRecorder::class)->delete($annotation);

            if ($this->selectedAnnotationId === $annotationId) {
                $this->selectedAnnotationId = null;
                $this->label = '';
                $this->notes = '';
            }

            Notification::make()
                ->success()
                ->title(__('lba.exams.workspace.annotation_deleted'))
                ->send();
        } catch (ExaminationException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();
        }
    }

    public function submitForReview(): void
    {
        if (! $this->canSubmitForReview()) {
            return;
        }

        $actor = auth()->user();

        try {
            app(ExaminationWorkflow::class)->transition(
                $this->examination(),
                Examination::STATUS_REVIEW_REQUIRED,
                $actor instanceof User ? $actor : null,
            );

            Notification::make()
                ->success()
                ->title(__('lba.exams.status_changed'))
                ->send();
        } catch (ExaminationException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();
        }
    }
}
