<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Access\PermissionKey;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\ReportVersion;
use Throwable;

/**
 * Phase 8A — "Hasil Pemeriksaan Saya".
 *
 * Lists ONLY the immutable report versions of the acting identity's OWN
 * examinations — and only where an authorized clinic identity has EXPLICITLY
 * RELEASED that exact version to the patient portal. Reviewer approval alone
 * never publishes: an approved-but-unreleased version, a pre-review AI result
 * and a raw clinical object can never surface here.
 *
 * Preview/download link straight to the authenticated, self-scoped
 * `lba.patient.reports.*` stream endpoints, which re-authorise every request
 * on the server and enforce the same release gate. Requires
 * `report.read.published`.
 */
class MyResults extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'hasil-saya';

    protected static ?int $navigationSort = 13;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected string $view = 'filament.patient.pages.my-results';

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::REPORT_READ_PUBLISHED);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.my_results');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.results.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.results.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.results.subtitle');
    }

    /**
     * The acting identity's OWN released report versions, newest first. Fails
     * closed to an empty list.
     *
     * @return Collection<int, ReportVersion>
     */
    public function reports(): Collection
    {
        $patient = $this->patient();

        if ($patient === null) {
            return collect();
        }

        try {
            return ReportVersion::query()
                ->with(['assessment.examination.branch'])
                ->whereNotNull('released_at')
                ->whereHas('assessment', function ($query) use ($patient): void {
                    $query
                        ->where('status', Assessment::STATUS_REVIEWED)
                        ->whereHas('examination', fn ($examination) => $examination->where('patient_id', $patient->getKey()));
                })
                ->orderByDesc('generated_at')
                ->orderByDesc('created_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function previewUrl(ReportVersion $version): string
    {
        return route('lba.patient.reports.preview', ['report' => $version->getKey()]);
    }

    public function downloadUrl(ReportVersion $version): string
    {
        return route('lba.patient.reports.download', ['report' => $version->getKey()]);
    }

    public function branchName(ReportVersion $version): string
    {
        return (string) ($version->assessment?->examination?->branch?->name ?? '—');
    }
}
