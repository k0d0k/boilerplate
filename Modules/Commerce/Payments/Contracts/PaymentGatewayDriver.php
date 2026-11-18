<?php

namespace Modules\Commerce\Payments\Contracts;

use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Payments\Support\PaymentRequest;
use Modules\Commerce\Payments\Support\PaymentResult;
use Modules\Commerce\Payments\Support\WebhookEvent;

/**
 * WS3 — a payment gateway adapter.
 *
 * One implementation per provider (Midtrans / iPaymu / PayPal). A driver is
 * stateless and receives the resolved {@see PaymentGateway} configuration row
 * on every call, so it never caches or leaks credentials.
 *
 * A driver is responsible for:
 *  - reporting whether its configuration is complete (`isConfigured`);
 *  - creating a charge / hosted checkout and returning a redirect target;
 *  - verifying the authenticity of an incoming webhook; and
 *  - normalising a webhook into a gateway-agnostic {@see WebhookEvent}.
 *
 * Drivers only TRANSPORT money events; they never mutate orders themselves — the
 * {@see PaymentManager} settles the tenant order
 * idempotently after a webhook is verified.
 */
interface PaymentGatewayDriver
{
    /**
     * The stable provider key (matches the central `payment_gateways.provider`).
     */
    public function provider(): string;

    /**
     * Credential field names this driver requires to operate.
     *
     * @return list<string>
     */
    public function requiredCredentials(): array;

    /**
     * True when every required credential is present on the gateway row.
     */
    public function isConfigured(PaymentGateway $gateway): bool;

    /**
     * Create a charge / hosted checkout for the request.
     */
    public function charge(PaymentGateway $gateway, PaymentRequest $request): PaymentResult;

    /**
     * True when the incoming webhook is authentic for this gateway.
     */
    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool;

    /**
     * Normalise a (verified) webhook into a gateway-agnostic event.
     */
    public function parseWebhook(PaymentGateway $gateway, Request $request): WebhookEvent;
}
