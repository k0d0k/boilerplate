<?php

namespace Modules\Appointments\Filament;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Filament\AbstractModuleRegistration;
use Modules\Appointments\Filament\Resources\AppointmentResource;

/**
 * Phase 3 — Appointments module-to-panel registration.
 *
 * Declares the module's Filament resource for the Clinic panel only. The
 * resource is gated server-side by the FeatureGate and RBAC; this registration
 * is only ever applied to a panel both the manifest and this class allow.
 */
final class AppointmentsRegistration extends AbstractModuleRegistration
{
    public function key(): string
    {
        return 'appointments';
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
        return [AppointmentResource::class];
    }
}
