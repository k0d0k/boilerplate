<?php

namespace Modules\LBA\Filament;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Filament\AbstractModuleRegistration;
use Modules\LBA\Filament\Pages\AiWalletOverview;
use Modules\LBA\Filament\Pages\AssessmentForm;
use Modules\LBA\Filament\Pages\AssessmentReview;
use Modules\LBA\Filament\Pages\BrandReportSettings;
use Modules\LBA\Filament\Pages\MicroscopeWorkspace;
use Modules\LBA\Filament\Pages\ReportsOverview;
use Modules\LBA\Filament\Pages\ReviewerInbox;
use Modules\LBA\Filament\Resources\ExaminationResource;

/**
 * Phase 4 — LBA module-to-panel registration.
 *
 * Declares the module's Filament resource and microscope workspace page for the
 * Clinic panel only. Both are gated server-side by the FeatureGate and RBAC;
 * this registration is only ever applied to a panel both the manifest and this
 * class allow.
 */
final class LbaRegistration extends AbstractModuleRegistration
{
    public function key(): string
    {
        return 'lba';
    }

    /**
     * @return list<string>
     */
    public function panels(): array
    {
        return [PanelAccessPolicy::PANEL_CLINIC];
    }

    /**
     * @return list<class-string>
     */
    public function resources(): array
    {
        return [ExaminationResource::class];
    }

    /**
     * @return list<class-string>
     */
    public function pages(): array
    {
        return [
            MicroscopeWorkspace::class,
            AiWalletOverview::class,
            AssessmentForm::class,
            ReviewerInbox::class,
            AssessmentReview::class,
            BrandReportSettings::class,
            ReportsOverview::class,
        ];
    }

    public function navigationGroup(): ?string
    {
        return __('lba.exams.nav_group');
    }
}
