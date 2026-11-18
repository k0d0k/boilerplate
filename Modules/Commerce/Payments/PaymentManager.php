<?php

namespace Modules\Commerce\Payments;

use App\Models\PaymentGateway;
use App\Models\PaymentIntent;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Http\Request;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Payments\Contracts\PaymentGatewayDriver;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\Support\PaymentInitiation;
use Modules\Commerce\Payments\Support\PaymentRequest;
use Modules\Commerce\Payments\Support\WebhookEvent;
use Modules\Commerce\Services\PaymentSettlement;
use Modules\Commerce\Services\RefundService;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — the central payment orchestrator.
 *
 * Resolves a provider's central configuration row and its adapter, enforces the
 * live/sandbox rules, initiates hosted charges and settles verified webhooks
 * idempotently.
 *
 * Live payments fail closed: a charge is only attempted when the master switch
 * `payments.live_enabled` is on AND the gateway row is enabled, fully
 * configured and verified. Sandbox charges stay available for integration.
 */
final class PaymentManager
{
    /**
     * How long a charge reservation may sit unfinished before a retry may
     * re-attempt the provider call. It only ever matters after a process died
     * BETWEEN reserving the intent and recording the provider's answer.
     */
    private const CHARGE_RESERVATION_TTL = 120;

    /** @var array<string, PaymentGatewayDriver> */
    private array $drivers = [];

    public function __construct(private readonly ResolvesTenantDatabase $databases) {}

    /**
     * Every provider the platform knows how to talk to.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_values(array_keys((array) config('payments.gateways', [])));
    }

    public function driver(string $provider): PaymentGatewayDriver
    {
        if (isset($this->drivers[$provider])) {
            return $this->drivers[$provider];
        }

        $driverClass = config("payments.gateways.{$provider}.driver");

        if (! is_string($driverClass) || ! class_exists($driverClass)) {
            throw PaymentException::unknownProvider($provider);
        }

        /** @var PaymentGatewayDriver $driver */
        $driver = app($driverClass);

        return $this->drivers[$provider] = $driver;
    }

    /**
     * The central configuration row for a provider, or null when it is absent.
     */
    public function gateway(string $provider): ?PaymentGateway
    {
        return PaymentGateway::query()->where('provider', $provider)->first();
    }

    public function gatewayOrFail(string $provider): PaymentGateway
    {
        return $this->gateway($provider) ?? throw PaymentException::gatewayMissing($provider);
    }

    /**
     * Credential field names the provider's driver requires.
     *
     * @return list<string>
     */
    public function requiredCredentials(string $provider): array
    {
        return $this->driver($provider)->requiredCredentials();
    }

    /**
     * Provider => label options for a form, including providers not yet
     * configured (so an operator can add them).
     *
     * @return array<string, string>
     */
    public function providerOptions(): array
    {
        $options = [];

        foreach ($this->providers() as $provider) {
            $options[$provider] = $this->gateway($provider)?->label ?: ucfirst($provider);
        }

        return $options;
    }

    /**
     * Providers that may currently take a payment in the given currency.
     *
     * @return array<string, string>
     */
    public function availableFor(string $currency): array
    {
        $currency = Currency::assertSupported($currency);
        $options = [];

        foreach ($this->providers() as $provider) {
            $gateway = $this->gateway($provider);

            if ($gateway === null || ! $gateway->enabled) {
                continue;
            }

            if (! $this->driver($provider)->isConfigured($gateway)) {
                continue;
            }

            if (! $gateway->allowsCurrency($currency)) {
                continue;
            }

            if ($gateway->isLive() && (! config('payments.live_enabled') || ! $gateway->isVerified())) {
                continue;
            }

            $options[$provider] = $gateway->label ?: ucfirst($provider);
        }

        return $options;
    }

    /**
     * True when the provider could charge the currency right now (the same
     * rules as {@see availableFor}, for one provider).
     */
    public function isAvailable(string $provider, string $currency): bool
    {
        return array_key_exists($provider, $this->availableFor($currency));
    }

    /**
     * Initiate a hosted charge for an order's outstanding balance.
     *
     * A DURABLE idempotency barrier precedes the external provider call: the
     * central intent is reserved (on its unique key) BEFORE the provider is
     * contacted and carries a stable provider-order reference. A retried or
     * concurrent request therefore cannot create a second external charge — it
     * either replays the already-created hosted redirect or is refused while an
     * attempt is still in flight.
     *
     * @param  array{return_url?: string, notify_url?: string}  $options
     */
    public function charge(Order $order, string $provider, array $options = []): PaymentInitiation
    {
        $gateway = $this->gatewayOrFail($provider);
        $driver = $this->driver($provider);
        $currency = strtoupper((string) $order->currency);

        $this->assertChargeable($gateway, $driver, $currency);

        $outstanding = $order->outstanding();

        if ($outstanding <= 0) {
            throw OrderException::alreadySettled((string) $order->order_number);
        }

        $tenantId = $this->databases->current();

        if ($tenantId === null) {
            throw PaymentException::chargeFailed($provider, 'no verified tenant context');
        }

        // ── Durable barrier: reserve the intent BEFORE the external call. The
        // unique key makes this atomic, so only one attempt can ever proceed.
        $intent = PaymentIntent::query()->firstOrCreate(
            ['idempotency_key' => $provider.':intent:'.$order->order_number],
            [
                'tenant_id' => $tenantId,
                'order_id' => (string) $order->getKey(),
                'order_number' => (string) $order->order_number,
                'provider' => $provider,
                'provider_reference' => (string) $order->order_number,
                'currency' => $currency,
                'amount' => $outstanding,
                'status' => PaymentIntent::STATUS_INITIATED,
            ],
        );

        if (! $intent->wasRecentlyCreated) {
            // A charge already reached the provider: replay the stored hosted
            // redirect instead of charging the buyer twice.
            if ($intent->status !== PaymentIntent::STATUS_INITIATED) {
                return new PaymentInitiation($intent, data_get($intent->payload, 'redirect_url'));
            }

            // Still reserved by an attempt in flight: refuse rather than race.
            if ($this->reservationIsFresh($intent)) {
                throw PaymentException::chargeInProgress($provider, (string) $order->order_number);
            }

            // A STALE reservation (a crashed attempt) may be retried safely.
            $intent->forceFill(['updated_at' => now()])->save();
        }

        $request = new PaymentRequest(
            orderNumber: (string) $order->order_number,
            amount: Currency::round($outstanding, $currency),
            currency: $currency,
            customerName: $order->customer_name,
            customerEmail: $order->customer_email,
            customerPhone: $order->customer_phone,
            returnUrl: (string) ($options['return_url'] ?? url('/')),
            notifyUrl: (string) ($options['notify_url'] ?? $this->webhookUrl($provider)),
            metadata: [
                'order_id' => (string) $order->getKey(),
                'tenant_id' => $tenantId,
            ],
        );

        try {
            $result = $driver->charge($gateway, $request);
        } catch (PaymentException $exception) {
            // No charge was created, so the reservation is released for a clean
            // retry (the intent is only a routing index at this point).
            $intent->delete();

            throw $exception;
        }

        $intent->forceFill([
            'status' => PaymentIntent::STATUS_PENDING,
            'provider_reference' => $result->providerReference ?: (string) $order->order_number,
            'payload' => ['redirect_url' => $result->redirectUrl],
        ])->save();

        // Mirror the initiation onto the tenant ledger (append-only history).
        app(PaymentSettlement::class)->record($order, [
            'provider' => $provider,
            'provider_reference' => $result->providerReference,
            'amount' => $outstanding,
            'currency' => $currency,
            'status' => PaymentTransaction::STATUS_PENDING,
            'request_payload' => $request->metadata,
            'response_payload' => $result->raw,
        ]);

        return new PaymentInitiation($intent->refresh(), $result->redirectUrl);
    }

    /**
     * Whether a reservation is recent enough that a concurrent duplicate must
     * be refused rather than allowed to re-charge.
     */
    private function reservationIsFresh(PaymentIntent $intent): bool
    {
        if ($intent->updated_at === null) {
            return false;
        }

        return $intent->updated_at->gt(now()->subSeconds(self::CHARGE_RESERVATION_TTL));
    }

    /**
     * Verify and settle a gateway webhook. The originating tenant is resolved
     * from the central intent registry; settlement is idempotent, so a replayed
     * webhook is a no-op.
     */
    public function handleWebhook(string $provider, Request $request): PaymentIntent
    {
        $gateway = $this->gatewayOrFail($provider);
        $driver = $this->driver($provider);

        if (config('payments.webhook.verify_signature') && ! $driver->verifyWebhook($gateway, $request)) {
            throw PaymentException::invalidSignature($provider);
        }

        $event = $driver->parseWebhook($gateway, $request);
        $intent = $this->resolveIntent($provider, $event);

        if ($intent === null) {
            throw PaymentException::unresolvedWebhook($provider);
        }

        // Hardening: a verified signature still cannot make us settle a
        // CONTRADICTORY event. The event's currency must equal the intent's, and
        // a success may never claim more than the amount we asked to charge —
        // otherwise the notification is rejected and nothing is settled.
        $eventCurrency = strtoupper((string) ($event->currency ?: $intent->currency));

        if ($eventCurrency !== strtoupper((string) $intent->currency)) {
            throw PaymentException::webhookCurrencyMismatch($provider);
        }

        if ($event->isSuccess() && $event->amount !== null
            && (float) $event->amount > (float) $intent->amount + 0.0001) {
            throw PaymentException::webhookAmountMismatch($provider);
        }

        $this->runInTenant((string) $intent->tenant_id, function () use ($intent, $event, $provider): void {
            $order = Order::query()->find($intent->order_id);

            if ($order === null) {
                throw PaymentException::unresolvedWebhook($provider);
            }

            // A provider-confirmed refund is a reversal, not a settlement: it
            // completes the still-pending remote refund request (never a
            // local-only fake refund).
            if ($event->status === PaymentTransaction::STATUS_REFUNDED) {
                app(RefundService::class)->settleProviderRefund(
                    $order,
                    $provider,
                    (float) ($event->amount ?? $intent->amount),
                    'Provider refund confirmed',
                    $event->raw,
                );

                return;
            }

            app(PaymentSettlement::class)->settle(
                $order,
                $provider,
                $event->providerReference ?: $intent->provider_reference,
                $event->status,
                (float) ($event->amount ?? $intent->amount),
                (string) ($event->currency ?? $intent->currency),
                $event->raw,
            );
        });

        $intent->forceFill([
            'status' => $event->status,
            'provider_reference' => $intent->provider_reference ?: $event->providerReference,
        ])->save();

        return $intent->refresh();
    }

    private function resolveIntent(string $provider, WebhookEvent $event): ?PaymentIntent
    {
        return PaymentIntent::query()
            ->where('provider', $provider)
            ->when(
                filled($event->orderNumber),
                fn ($query) => $query->where('order_number', $event->orderNumber),
                fn ($query) => $query->where('provider_reference', $event->providerReference),
            )
            ->latest('created_at')
            ->first();
    }

    private function assertChargeable(PaymentGateway $gateway, PaymentGatewayDriver $driver, string $currency): void
    {
        if (! $gateway->enabled) {
            throw PaymentException::gatewayDisabled((string) $gateway->provider);
        }

        if (! $driver->isConfigured($gateway)) {
            throw PaymentException::notConfigured((string) $gateway->provider);
        }

        if (! $gateway->allowsCurrency($currency)) {
            throw PaymentException::unsupportedCurrency((string) $gateway->provider, $currency);
        }

        if ($gateway->isLive()) {
            if (! config('payments.live_enabled')) {
                throw PaymentException::liveDisabled((string) $gateway->provider);
            }

            if (! $gateway->isVerified()) {
                throw PaymentException::notVerified((string) $gateway->provider);
            }
        }
    }

    /**
     * Run a callback with the tenant connection pointed at the given tenant,
     * restoring the previous binding afterwards (fail-closed: the connection is
     * always forgotten when there was none).
     */
    private function runInTenant(string $tenantId, callable $callback): void
    {
        $previous = $this->databases->current();

        $this->databases->configure($tenantId);

        try {
            $callback();
        } finally {
            if ($previous === null) {
                $this->databases->forget();
            } else {
                $this->databases->configure($previous);
            }
        }
    }

    private function webhookUrl(string $provider): string
    {
        $prefix = trim((string) config('payments.webhook.prefix', 'api/v1/payments'), '/');

        return url($prefix.'/'.$provider.'/webhook');
    }
}
