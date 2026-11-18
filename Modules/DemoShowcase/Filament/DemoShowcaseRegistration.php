<?php

namespace Modules\DemoShowcase\Filament;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Filament\AbstractModuleRegistration;
use Modules\DemoShowcase\Filament\Pages\DemoShowcasePage;

/**
 * Phase 1B-4 demonstration module-to-panel registration.
 *
 * Declares the module's single protected page for the Clinic panel only. The
 * page is hidden from navigation and gated server-side by the FeatureGate; this
 * registration is only ever applied to a panel both the manifest and this class
 * allow.
 */
final class DemoShowcaseRegistration extends AbstractModuleRegistration
{
    public function key(): string
    {
        return 'demo.showcase';
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
    public function pages(): array
    {
        return [DemoShowcasePage::class];
    }
}
