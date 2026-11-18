<?php

namespace Modules\Commerce\Filament\Pages;

use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Models\InventoryStock;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\OrderItem;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — Clinic panel sales dashboard.
 *
 * A real, branch-scoped overview of the verified tenant's sales for the last 30
 * days. Money is NEVER summed across currencies: each supported currency is
 * reported on its own line, so an IDR total can never be added to a MYR total.
 */
class SalesOverview extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    protected static ?string $slug = 'commerce/sales';

    protected static ?int $navigationSort = 43;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':commerce',
    ];

    protected string $view = 'commerce::pages.sales-overview';

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
        return __('lba.commerce.sales.nav');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.commerce.sales.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.commerce.sales.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.commerce.sales.subtitle');
    }

    protected function windowStart(): Carbon
    {
        return now()->subDays(30)->startOfDay();
    }

    /**
     * @return Collection<int, Order>
     */
    protected function windowOrders(): Collection
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return collect();
        }

        $branches = static::accessibleBranchIds();

        if ($branches === []) {
            return collect();
        }

        return Order::query()
            ->whereIn('branch_id', $branches)
            ->where('placed_at', '>=', $this->windowStart())
            ->get();
    }

    /**
     * Per-currency summary for the last 30 days. Money never crosses currencies.
     *
     * @return array<string, array{orders: int, sales: float, outstanding: float, paid: int}>
     */
    public function summary(): array
    {
        $summary = [];

        foreach (Currency::SUPPORTED as $currency) {
            $summary[$currency] = ['orders' => 0, 'sales' => 0.0, 'outstanding' => 0.0, 'paid' => 0];
        }

        foreach ($this->windowOrders() as $order) {
            $currency = strtoupper((string) $order->currency);

            if (! isset($summary[$currency])) {
                continue;
            }

            if ($order->isCancelled()) {
                continue;
            }

            $summary[$currency]['orders']++;
            $summary[$currency]['sales'] += (float) $order->grand_total;
            $summary[$currency]['outstanding'] += max($order->outstanding(), 0.0);

            if ($order->isPaid()) {
                $summary[$currency]['paid']++;
            }
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function statusBreakdown(): array
    {
        $breakdown = array_fill_keys(Order::STATUSES, 0);

        foreach ($this->windowOrders() as $order) {
            $breakdown[$order->status] = ($breakdown[$order->status] ?? 0) + 1;
        }

        return $breakdown;
    }

    /**
     * Latest orders of the window.
     *
     * @return Collection<int, Order>
     */
    public function recentOrders(): Collection
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return collect();
        }

        $branches = static::accessibleBranchIds();

        if ($branches === []) {
            return collect();
        }

        return Order::query()
            ->whereIn('branch_id', $branches)
            ->orderByDesc('placed_at')
            ->limit(10)
            ->get();
    }

    /**
     * Best-selling products in the window, by quantity.
     *
     * @return Collection<int, OrderItem>
     */
    public function topProducts(): Collection
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return collect();
        }

        $branches = static::accessibleBranchIds();

        if ($branches === []) {
            return collect();
        }

        return OrderItem::query()
            ->selectRaw('name, currency, SUM(quantity) as sold_quantity, SUM(line_total) as sold_total')
            ->whereHas('order', fn ($query) => $query
                ->whereIn('branch_id', $branches)
                ->where('placed_at', '>=', $this->windowStart())
                ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED]))
            ->groupBy('name', 'currency')
            ->orderByDesc('sold_quantity')
            ->limit(8)
            ->get();
    }

    public function format(float|int|string $amount, string $currency): string
    {
        return Currency::format($amount, $currency);
    }

    /**
     * Real, DB-driven stock valuation for the branches the identity may operate
     * in, keyed by each holding product's base currency so an IDR valuation is
     * never mixed with a MYR one. Only positive holdings are valued; a product
     * the currency cannot price contributes nothing.
     *
     * @return array<string, float>
     */
    public function inventoryValue(): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        $branches = static::accessibleBranchIds();

        if ($branches === []) {
            return [];
        }

        $values = [];

        foreach (InventoryStock::query()->with('product')->whereIn('branch_id', $branches)->where('quantity', '>', 0)->get() as $stock) {
            $product = $stock->product;

            if ($product === null) {
                continue;
            }

            $currency = strtoupper((string) $product->base_currency);

            if (! in_array($currency, Currency::SUPPORTED, true)) {
                continue;
            }

            $price = $product->priceFor($currency);

            if ($price === null) {
                continue;
            }

            $values[$currency] = ($values[$currency] ?? 0.0) + ($price * (int) $stock->quantity);
        }

        foreach ($values as $currency => $value) {
            $values[$currency] = (float) Currency::round($value, $currency);
        }

        return $values;
    }

    /**
     * Count of branch stock rows at or below their reorder level, for the
     * branches the identity may operate in.
     */
    public function lowStockCount(): int
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return 0;
        }

        $branches = static::accessibleBranchIds();

        if ($branches === []) {
            return 0;
        }

        return InventoryStock::query()
            ->whereIn('branch_id', $branches)
            ->whereNotNull('reorder_level')
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->count();
    }

    public static function orderUrl(Order $order): string
    {
        return OrderResource::getUrl('view', ['record' => $order]);
    }
}
