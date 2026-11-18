<?php

namespace Modules\Commerce\Filament\Pages;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\PaymentSettlement;
use Modules\Commerce\Support\Currency;
use Modules\Patients\Models\Patient;

/**
 * WS3 — Clinic POS cashier terminal.
 *
 * A single-screen point of sale for the verified tenant: pick a branch, build a
 * cart from the active catalog in ONE explicit currency, optionally attach the
 * patient and take cash. Checkout turns the cart into a real order through
 * {@see OrderService} and a cash tender is settled through
 * {@see PaymentSettlement} — the same single writers the rest of the module
 * uses, so no money path is duplicated.
 *
 * The branch is re-validated server-side (it must be one the identity may
 * operate in) and every product price is re-resolved for the selected currency
 * at checkout time, so the browser can never dictate a price.
 */
class PosTerminal extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    public const METHOD_CASH = 'cash';

    public const METHOD_DEFERRED = 'deferred';

    public const METHOD_PROVIDER = 'provider';

    /** @var list<string> */
    public const METHODS = [self::METHOD_CASH, self::METHOD_DEFERRED, self::METHOD_PROVIDER];

    protected static ?string $slug = 'commerce/pos';

    protected static ?int $navigationSort = 44;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':commerce',
    ];

    protected string $view = 'commerce::pages.pos-terminal';

    public ?string $branchId = null;

    public string $currency = Currency::IDR;

    public string $search = '';

    /** @var array<string, array{product_id: string, name: string, sku: ?string, unit_price: float, quantity: int}> */
    public array $lines = [];

    public string $discountTotal = '0';

    public string $taxTotal = '0';

    public ?string $patientId = null;

    public string $customerName = '';

    public string $customerEmail = '';

    public string $customerPhone = '';

    public string $notes = '';

    public string $paymentMethod = self::METHOD_CASH;

    /**
     * The sandbox provider selected for a provider tender. A provider checkout
     * NEVER marks the order paid here — only a verified webhook may settle it.
     */
    public ?string $provider = null;

    public ?string $lastOrderNumber = null;

    public ?string $lastRedirectUrl = null;

    /**
     * A stable key for the CURRENT checkout attempt. It is passed to
     * {@see OrderService::place()} so a double-click (or a retried request)
     * returns the already-placed order instead of charging/stocking twice.
     */
    public string $checkoutToken = '';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::ORDER_MANAGE);
    }

    public function mount(): void
    {
        $branches = static::accessibleBranchOptions();

        $this->branchId = array_key_first($branches);
        $this->currency = Currency::IDR;
        $this->checkoutToken = (string) Str::ulid();
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.commerce.pos.nav');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.commerce.pos.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.commerce.pos.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.commerce.pos.subtitle');
    }

    /**
     * @return array<string, string>
     */
    public function branchOptions(): array
    {
        return static::accessibleBranchOptions();
    }

    /**
     * @return array<string, string>
     */
    public function currencyOptions(): array
    {
        $options = [];

        foreach (Currency::SUPPORTED as $code) {
            $options[$code] = $code;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function methodOptions(): array
    {
        $options = [];

        foreach (self::METHODS as $method) {
            $options[$method] = __("lba.commerce.pos.method_{$method}");
        }

        return $options;
    }

    /**
     * Sandbox gateways that could charge the CURRENT cart currency right now.
     *
     * Sourced from {@see PaymentManager::availableFor()}, so the list already
     * excludes disabled, misconfigured, currency-incompatible and (unless the
     * master switch is on and the gateway is verified) live gateways. A browser
     * can never widen this set.
     *
     * @return array<string, string>
     */
    public function providerOptions(): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return app(PaymentManager::class)->availableFor($this->currency);
    }

    /**
     * Active patients of the verified tenant, for the optional link.
     *
     * @return array<string, string>
     */
    public function patientOptions(): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return Patient::query()
            ->active()
            ->orderBy('full_name')
            ->pluck('full_name', 'id')
            ->all();
    }

    /**
     * Sellable products (active) matching the search, newest catalog first.
     *
     * @return Collection<int, Product>
     */
    public function products(): Collection
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return collect();
        }

        return Product::query()
            ->active()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term);
                });
            })
            ->orderBy('name')
            ->limit(24)
            ->get();
    }

    public function addProduct(string $productId, int $quantity = 1): void
    {
        if ($quantity < 1) {
            return;
        }

        $product = Product::query()->whereKey($productId)->first();

        if ($product === null) {
            return;
        }

        $price = $product->priceFor($this->currency);

        if ($price === null) {
            Notification::make()
                ->danger()
                ->title(__('lba.commerce.pos.unpriced', ['currency' => $this->currency]))
                ->send();

            return;
        }

        $key = (string) $product->getKey();
        $existing = $this->lines[$key]['quantity'] ?? 0;

        $this->lines[$key] = [
            'product_id' => $key,
            'name' => (string) $product->name,
            'sku' => $product->sku,
            'unit_price' => $price,
            'quantity' => $existing + $quantity,
        ];
    }

    public function setQuantity(string $productId, int $quantity): void
    {
        if (! isset($this->lines[$productId])) {
            return;
        }

        if ($quantity <= 0) {
            unset($this->lines[$productId]);

            return;
        }

        $this->lines[$productId]['quantity'] = $quantity;
    }

    public function removeLine(string $productId): void
    {
        unset($this->lines[$productId]);
    }

    public function clearCart(): void
    {
        $this->lines = [];
        $this->discountTotal = '0';
        $this->taxTotal = '0';
    }

    public function subtotal(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $total += $line['unit_price'] * $line['quantity'];
        }

        return (float) Currency::round($total, $this->currency);
    }

    public function grandTotal(): float
    {
        $grand = $this->subtotal() - (float) $this->discountTotal + (float) $this->taxTotal;

        return (float) Currency::round(max($grand, 0), $this->currency);
    }

    public function format(float|int|string $amount): string
    {
        return Currency::format($amount, $this->currency);
    }

    /**
     * Place the cart as an order and take payment.
     *
     * Cash settles immediately through {@see PaymentSettlement}. A provider
     * tender only INITIATES a sandbox charge and surfaces the hosted redirect
     * URL — the order stays open until a verified provider webhook settles it,
     * so a browser return can never mark an order paid. Deferred leaves the
     * order open with no payment.
     *
     * A stable {@see self::$checkoutToken} makes the whole action idempotent:
     * a double-click returns the same order and does not charge or stock twice.
     */
    public function checkout(): void
    {
        if (! $this->canCheckout()) {
            Notification::make()
                ->danger()
                ->title(__('lba.commerce.pos.unavailable'))
                ->send();

            return;
        }

        $lines = [];

        foreach ($this->lines as $line) {
            $lines[] = [
                'product_id' => $line['product_id'],
                'quantity' => (int) $line['quantity'],
            ];
        }

        try {
            $order = app(OrderService::class)->place($lines, [
                'branch_id' => (string) $this->branchId,
                'currency' => $this->currency,
                'source' => Order::SOURCE_POS,
                'idempotency_key' => 'pos:'.$this->checkoutToken,
                'patient_id' => $this->patientId,
                'customer_name' => $this->customerName !== '' ? $this->customerName : null,
                'customer_email' => $this->customerEmail !== '' ? $this->customerEmail : null,
                'customer_phone' => $this->customerPhone !== '' ? $this->customerPhone : null,
                'discount_total' => (float) $this->discountTotal,
                'tax_total' => (float) $this->taxTotal,
                'notes' => $this->notes !== '' ? $this->notes : null,
            ], $this->actor());

            $this->lastOrderNumber = (string) $order->order_number;
            $this->lastRedirectUrl = null;

            match ($this->paymentMethod) {
                self::METHOD_CASH => app(PaymentSettlement::class)->record($order, [
                    'provider' => self::METHOD_CASH,
                    'method' => self::METHOD_CASH,
                    'currency' => $this->currency,
                    'amount' => (float) $order->grand_total,
                ]),
                self::METHOD_PROVIDER => $this->initiateProviderCharge($order),
                default => null,
            };

            Notification::make()
                ->success()
                ->title(__('lba.commerce.pos.placed', ['number' => $order->order_number]))
                ->send();

            $this->reset('lines', 'patientId', 'customerName', 'customerEmail', 'customerPhone', 'notes');
            $this->discountTotal = '0';
            $this->taxTotal = '0';
            // A fresh key for the NEXT sale so the completed one can be replayed
            // safely but a new cart is a distinct order.
            $this->checkoutToken = (string) Str::ulid();
        } catch (OrderException|PaymentException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();
        }
    }

    /**
     * Initiate a hosted sandbox charge for the order's outstanding balance.
     *
     * The order is deliberately left OPEN: only a verified provider webhook can
     * settle it. The hosted redirect URL is stored for display.
     */
    private function initiateProviderCharge(Order $order): void
    {
        /** @var string $provider */
        $provider = (string) $this->provider;

        $initiation = app(PaymentManager::class)->charge($order, $provider, [
            'return_url' => url('/clinic/commerce/pos'),
        ]);

        $this->lastRedirectUrl = $initiation->redirectUrl;
    }

    public function canCheckout(): bool
    {
        $base = static::moduleActive()
            && static::allows(PermissionKey::ORDER_MANAGE)
            && $this->lines !== []
            && in_array($this->currency, Currency::SUPPORTED, true)
            && in_array((string) $this->branchId, static::accessibleBranchIds(), true);

        if (! $base) {
            return false;
        }

        // A provider tender needs a REAL, currently-chargeable sandbox gateway:
        // the browser may only pick one the platform already offers.
        if ($this->paymentMethod === self::METHOD_PROVIDER) {
            return $this->provider !== null
                && array_key_exists($this->provider, $this->providerOptions());
        }

        return true;
    }

    protected function actor(): ?User
    {
        return static::currentUser();
    }

    /**
     * The verified tenant id, for display only.
     */
    public function tenantId(): ?string
    {
        $user = static::currentUser();

        if ($user === null) {
            return null;
        }

        return app(ManagesTenantContext::class)->currentTenantId($user);
    }
}
