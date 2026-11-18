<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Models\TenantBranch;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Support\Currency;
use Throwable;

/**
 * WS3 — "Produk" (patient shop).
 *
 * The patient-facing half of the commerce surface: it lists the bound clinic's
 * ACTIVE catalog with a price for ONE explicit currency (a product that cannot
 * be priced in the selected currency is shown as unavailable, never guessed)
 * and lets the acting identity place an ORDER for itself.
 *
 * The write goes through the SAME {@see OrderService} the clinic POS and the
 * REST API use, so branch existence, active-product and per-currency pricing
 * rules apply unchanged. `patient_id` is FORCED to the acting identity's own
 * patient record — a patient can only ever order for themselves. Requires the
 * `order.own` self-service permission.
 */
class Products extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'produk';

    protected static ?int $navigationSort = 15;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected string $view = 'filament.patient.pages.products';

    public ?string $branchId = null;

    public string $currency = Currency::IDR;

    /** @var array<string, int|string> */
    public array $quantities = [];

    public ?string $notes = null;

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::ORDER_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.products');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.products.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.products.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.products.subtitle');
    }

    /**
     * Active branches of the verified tenant as id => name options.
     *
     * @return array<string, string>
     */
    public function branchOptions(): array
    {
        try {
            return TenantBranch::query()
                ->active()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Supported currencies as code => code options (currency is always
     * explicit, never inferred from the interface language).
     *
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
     * The bound clinic's ACTIVE catalog, alphabetically. Fails closed to an
     * empty list.
     *
     * @return Collection<int, Product>
     */
    public function products(): Collection
    {
        try {
            return Product::query()
                ->active()
                ->with('prices')
                ->orderBy('name')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * The product's price in the selected currency, formatted, or null when it
     * is not priced in that currency (shown as unavailable).
     */
    public function priceLabel(Product $product): ?string
    {
        if (! Currency::isSupported($this->currency)) {
            return null;
        }

        $price = $product->priceFor($this->currency);

        return $price === null ? null : Currency::format($price, $this->currency);
    }

    public function submit(): void
    {
        // Self-only: the order is always for the acting identity's OWN record.
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::ORDER_OWN)) {
            abort(403);
        }

        $validated = $this->validate([
            'branchId' => ['required', 'string'],
            'currency' => ['required', 'string', 'in:'.implode(',', Currency::SUPPORTED)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if (! array_key_exists($validated['branchId'], $this->branchOptions())) {
            $this->addError('branchId', __('lba.patient.products.errors.branch'));

            return;
        }

        $lines = [];

        foreach ($this->quantities as $productId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity < 1) {
                continue;
            }

            $lines[] = ['product_id' => (string) $productId, 'quantity' => $quantity];
        }

        if ($lines === []) {
            $this->addError('quantities', __('lba.patient.products.errors.items'));

            return;
        }

        try {
            app(OrderService::class)->place($lines, [
                'branch_id' => $validated['branchId'],
                'currency' => strtoupper($validated['currency']),
                'source' => Order::SOURCE_ONLINE,
                'patient_id' => $patient->getKey(),
                'customer_name' => $patient->full_name,
                'customer_email' => $patient->email,
                'customer_phone' => $patient->phone,
                'notes' => $validated['notes'] ?? null,
            ], auth()->user());
        } catch (OrderException $exception) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.products.errors.failed'))
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lba.patient.products.created'))
            ->send();

        $this->redirect(MyOrders::getUrl(panel: 'patient'));
    }
}
