<?php

namespace Modules\LBA\Filament\Pages;

use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\LBA\Models\ReportVersion;
use Throwable;

/**
 * Phase 7E — the clinic REPORTS archive (Modules/LBA).
 *
 * A read-only listing of every IMMUTABLE report version the acting identity may
 * read for the VERIFIED tenant and the branches they may operate in. It is the
 * discovery surface on top of the existing private PDF services: preview and
 * download link straight to the authenticated `lba.reports.*` stream endpoints,
 * which re-authorise every request on the server.
 *
 * Only APPROVED assessments ever have a report version — an unapproved
 * assessment simply has nothing to list here — so this page can never publish a
 * report that a human reviewer has not signed. Every version is listed with its
 * full history so a revision (return → correct → re-approve) is visible.
 *
 * Access requires the module gate AND `exam.read`; cross-tenant and cross-branch
 * reports are never visible.
 */
class ReportsOverview extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'lba';

    protected static ?string $slug = 'lba/reports';

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':lba',
    ];

    protected static ?int $navigationSort = 28;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected string $view = 'lba::pages.reports-overview';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_READ);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.reports.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.exams.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.reports.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.reports.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.reports.subtitle');
    }

    /**
     * Every report version the identity may read, newest first, scoped to the
     * branches they may operate in. Fails closed to an empty list.
     *
     * @return Collection<int, ReportVersion>
     */
    public function reports(): Collection
    {
        $branches = static::accessibleBranchIds();

        if ($branches === []) {
            return collect();
        }

        try {
            return ReportVersion::query()
                ->with(['assessment.examination.patient', 'assessment.examination.branch'])
                ->whereHas('assessment.examination', fn ($query) => $query->whereIn('branch_id', $branches))
                ->orderByDesc('generated_at')
                ->orderByDesc('created_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function patientName(ReportVersion $version): string
    {
        return (string) ($version->assessment?->examination?->patient?->full_name ?? '—');
    }

    public function examinationId(ReportVersion $version): string
    {
        return (string) ($version->assessment?->examination_id ?? '—');
    }

    public function branchName(ReportVersion $version): string
    {
        return (string) ($version->assessment?->examination?->branch?->name ?? '—');
    }

    public function previewUrl(ReportVersion $version): string
    {
        return route('lba.reports.preview', ['report' => $version->getKey()]);
    }

    public function downloadUrl(ReportVersion $version): string
    {
        return route('lba.reports.download', ['report' => $version->getKey()]);
    }

    /**
     * Link to the human review page for the report's assessment, only when the
     * acting identity may actually open it (RBAC enforced there too).
     */
    public function reviewUrl(ReportVersion $version): ?string
    {
        $assessment = $version->assessment;

        if ($assessment === null || ! AssessmentReview::canAccess()) {
            return null;
        }

        return AssessmentReview::getUrl(['assessment' => $assessment->getKey()]);
    }

    public function humanSize(?int $bytes): string
    {
        $bytes = (int) $bytes;

        if ($bytes <= 0) {
            return '—';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return number_format($bytes / 1024, 1).' KB';
    }
}
