<?php

namespace Modules\Commerce\Filament\Pages;

use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Commerce\Services\ReconciliationService;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — Clinic panel reconciliation dashboard.
 *
 * A read-only view of the verified tenant's payment ledger: per-currency
 * collected / refunded / outstanding totals, plus any order whose recorded
 * settled total drifts from the ledger. Money never crosses currencies.
 */
class Reconciliation extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    protected static ?string $slug = 'commerce/reconciliation';

    protected static ?int $navigationSort = 48;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':commerce',
    ];

    protected string $view = 'commerce::pages.reconciliation';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::ORDER_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.commerce.reconciliation.nav');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.commerce.reconciliation.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.commerce.reconciliation.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.commerce.reconciliation.subtitle');
    }

    /**
     * The branches the acting identity may operate in. `null` (the tenant-wide
     * view) is returned only when the identity manages all branches.
     *
     * @return list<string>
     */
    protected function scopedBranchIds(): array
    {
        return static::accessibleBranchIds();
    }

    /**
     * @return array<string, array{collected: float, refunded: float, outstanding: float, orders: int, settled_orders: int}>
     */
    public function summary(): array
    {
        return app(ReconciliationService::class)->summary($this->scopedBranchIds());
    }

    /**
     * Per-merchant, per-currency collections and refunds for the branches the
     * identity may operate in. Funds are never pooled across partners.
     *
     * @return list<array{merchant_id: string|null, merchant: string, currency: string, collected: float, refunded: float, orders: int}>
     */
    public function byMerchant(): array
    {
        return app(ReconciliationService::class)->summaryByMerchant($this->scopedBranchIds());
    }

    /**
     * @return list<array{order_number: string, currency: string, recorded: string, expected: string, difference: string}>
     */
    public function discrepancies(): array
    {
        return app(ReconciliationService::class)->discrepancies(50, $this->scopedBranchIds());
    }

    public function format(float|int|string $amount, string $currency): string
    {
        return Currency::format($amount, $currency);
    }
}
