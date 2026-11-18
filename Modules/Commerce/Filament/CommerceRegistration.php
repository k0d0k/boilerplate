<?php

namespace Modules\Commerce\Filament;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Filament\AbstractModuleRegistration;
use Modules\Commerce\Filament\Pages\PosTerminal;
use Modules\Commerce\Filament\Pages\Reconciliation;
use Modules\Commerce\Filament\Pages\SalesOverview;
use Modules\Commerce\Filament\Resources\InvoiceResource;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Filament\Resources\PaymentTransactionResource;
use Modules\Commerce\Filament\Resources\ProductCategoryResource;
use Modules\Commerce\Filament\Resources\ProductResource;
use Modules\Commerce\Filament\Resources\StockLevelResource;

/**
 * WS3 — Commerce module-to-panel registration.
 *
 * Declares the module's Filament surfaces for the Clinic panel only. Every
 * surface is gated server-side by the FeatureGate and RBAC; this registration is
 * only ever applied to a panel both the manifest and this class allow.
 */
final class CommerceRegistration extends AbstractModuleRegistration
{
    public function key(): string
    {
        return 'commerce';
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
        return [
            ProductCategoryResource::class,
            ProductResource::class,
            StockLevelResource::class,
            OrderResource::class,
            InvoiceResource::class,
            PaymentTransactionResource::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public function pages(): array
    {
        return [
            SalesOverview::class,
            PosTerminal::class,
            Reconciliation::class,
        ];
    }

    public function navigationGroup(): ?string
    {
        return __('lba.nav.clinic.operations');
    }
}
