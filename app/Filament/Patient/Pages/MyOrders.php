<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Access\PermissionKey;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Support\Currency;
use Throwable;

/**
 * WS3 — "Pesanan Saya".
 *
 * Lists ONLY the acting identity's own orders (filtered by their own patient
 * id) with the per-currency total and status. A purchase is created through the
 * patient shop ("Produk") or the clinic POS; an OPEN order can be paid here
 * through the SAME {@see PaymentManager} the clinic POS and the REST API use.
 * A charge only ever INITIATES a hosted sandbox payment — the order is settled
 * exclusively by a verified provider webhook, never from a return URL. Requires
 * `order.own`.
 */
class MyOrders extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'pesanan-saya';

    protected static ?int $navigationSort = 14;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected string $view = 'filament.patient.pages.my-orders';

    /** @var array<string, string> */
    public array $providers = [];

    public ?string $redirectUrl = null;

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::ORDER_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.my_orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.shopping.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.shopping.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.shopping.subtitle');
    }

    /**
     * The acting identity's OWN orders, newest first. Fails closed.
     *
     * @return Collection<int, Order>
     */
    public function orders(): Collection
    {
        $patient = $this->patient();

        if ($patient === null) {
            return collect();
        }

        try {
            return Order::query()
                ->with('branch')
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('placed_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function statusLabel(string $status): string
    {
        return __('lba.patient.shopping.status_'.$status);
    }

    /**
     * Sandbox gateways that could charge this order's currency right now.
     *
     * @return array<string, string>
     */
    public function providerOptions(Order $order): array
    {
        if (! static::allows(PermissionKey::ORDER_OWN)) {
            return [];
        }

        try {
            return app(PaymentManager::class)->availableFor(strtoupper((string) $order->currency));
        } catch (Throwable) {
            return [];
        }
    }

    public function canPay(Order $order): bool
    {
        return $order->isOpen() && $this->providerOptions($order) !== [];
    }

    /**
     * Start a sandbox provider payment for the acting identity's OWN order.
     *
     * Self-scoped exactly like {@see self::orders()}: a foreign order id
     * resolves to nothing (404), never leaking existence. Only a verified
     * provider webhook settles the order — this action merely hands the buyer
     * the hosted redirect URL.
     */
    public function pay(string $orderId): void
    {
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::ORDER_OWN)) {
            abort(403);
        }

        $order = Order::query()
            ->with('branch')
            ->whereKey($orderId)
            ->where('patient_id', $patient->getKey())
            ->first();

        abort_if($order === null, 404);

        $currency = strtoupper((string) $order->currency);
        $provider = (string) ($this->providers[$orderId] ?? '');

        // The provider must be one the platform can actually charge right now
        // for this order's currency — the client can never widen the set.
        if ($provider === '' || ! app(PaymentManager::class)->isAvailable($provider, $currency) || ! $order->isOpen()) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.shopping.errors.pay'))
                ->send();

            return;
        }

        try {
            $initiation = app(PaymentManager::class)->charge($order, $provider, [
                'return_url' => static::getUrl(panel: 'patient'),
            ]);
        } catch (OrderException|PaymentException) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.shopping.errors.pay'))
                ->send();

            return;
        }

        $this->redirectUrl = $initiation->redirectUrl;

        Notification::make()
            ->success()
            ->title(__('lba.patient.shopping.payment_started'))
            ->send();
    }
}
