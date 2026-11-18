<?php

namespace Modules\LBA\Filament\Pages;

use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\LBA\Models\Assessment;
use Throwable;

/**
 * Phase 7B — the clinic REVIEWER INBOX (Modules/LBA).
 *
 * The single entry point of the human-review workflow: it lists the assessments
 * an eligible reviewer may act on for the VERIFIED tenant and the branches they
 * may operate in. It is read-only and never approves or generates anything —
 * approval is an explicit human action taken on the review page.
 *
 * Access requires the module gate AND the `exam.review` permission under the
 * verified tenant/branch context; an operator without `exam.review` never sees
 * another person's submitted work here.
 */
class ReviewerInbox extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'lba';

    protected static ?string $slug = 'lba/reviews';

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':lba',
    ];

    protected static ?int $navigationSort = 27;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected string $view = 'lba::pages.reviewer-inbox';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_REVIEW);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.review.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.exams.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.review.inbox_title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.review.inbox_title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.review.inbox_subtitle');
    }

    /**
     * Submitted assessments awaiting a human decision, scoped to the branches
     * the identity may operate in. Never crosses tenant or branch boundaries.
     *
     * @return Collection<int, Assessment>
     */
    public function pending(): Collection
    {
        return $this->scoped(Assessment::STATUS_SUBMITTED_FOR_REVIEW);
    }

    /**
     * Recently decided assessments (context for the reviewer), newest first.
     *
     * @return Collection<int, Assessment>
     */
    public function decided(): Collection
    {
        return $this->scoped(null)->take(20)->values();
    }

    /**
     * @return Collection<int, Assessment>
     */
    private function scoped(?string $status): Collection
    {
        $branches = static::accessibleBranchIds();

        if ($branches === []) {
            return collect();
        }

        try {
            return Assessment::query()
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->when($status === null, fn ($query) => $query->whereIn('status', [
                    Assessment::STATUS_REVIEWED,
                    Assessment::STATUS_REJECTED,
                    Assessment::STATUS_RETURNED_FOR_CHANGES,
                ]))
                ->whereHas('examination', fn ($query) => $query->whereIn('branch_id', $branches))
                ->orderByDesc('submitted_at')
                ->orderByDesc('created_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function patientName(Assessment $assessment): string
    {
        return (string) ($assessment->examination?->patient?->full_name ?? '—');
    }

    public function branchName(Assessment $assessment): string
    {
        return (string) ($assessment->examination?->branch?->name ?? '—');
    }

    public function statusLabel(string $status): string
    {
        return __('lba.assessment.status_'.$status);
    }
}
