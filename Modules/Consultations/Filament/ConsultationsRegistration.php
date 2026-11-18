<?php

namespace Modules\Consultations\Filament;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Filament\AbstractModuleRegistration;
use Modules\Consultations\Filament\Resources\ConsultationResource;

/**
 * Workstream 2 — Consultations module-to-panel registration.
 *
 * Declares the module's Clinic-panel resource. The Patient and Principal
 * surfaces that consume this module (the SINA chat portal and the AI provider
 * settings) are platform pages under `app/Filament/{Patient,Principal}/Pages`,
 * exactly like the other self-service and governance pages, so no module
 * resource is ever registered into those panels. Everything is gated
 * server-side by the FeatureGate and RBAC, and the manifest still authorises
 * the module for all three panels so those pages can gate on it.
 */
final class ConsultationsRegistration extends AbstractModuleRegistration
{
    public function key(): string
    {
        return 'consultations';
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
        return [ConsultationResource::class];
    }
}
