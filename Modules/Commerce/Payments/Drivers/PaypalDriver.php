<?php

namespace Modules\Commerce\Payments\Drivers;

use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Payments\Contracts\PaymentGatewayDriver;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\Support\PaymentRequest;
use Modules\Commerce\Payments\Support\PaymentResult;
use Modules\Commerce\Payments\Support\WebhookEvent;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — PayPal (Orders v2) adapter.
 *
 * Charges are created as PayPal Orders with `intent=CAPTURE`; the buyer is sent
 * to the `approve` link and the capture is confirmed asynchronously by webhook.
 * An access token is minted per charge from the client credentials, so no token
 * is ever cached across gateway configurations.
 *
 * Webhook authenticity uses PayPal's
 * `POST /v1/notifications/verify-webhook-signature` endpoint (the gateway row
 * must carry a `webhook_id`), which is the platform-sanctioned verification.
 */
final class PaypalDriver implements PaymentGatewayDriver
{
    public const PROVIDER = 'paypal';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function requiredCredentials(): array
    {
        return ['client_id', 'client_secret'];
    }

    public function isConfigured(PaymentGateway $gateway): bool
    {
        return $gateway->isConfigured($this->requiredCredentials());
    }

    public function charge(PaymentGateway $gateway, PaymentRequest $request): PaymentResult
    {
        $token = $this->accessToken($gateway);

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->base($gateway).'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $request->orderNumber,
                    'amount' => [
                        'currency_code' => $request->currency,
                        'value' => Currency::round((float) $request->amount, $request->currency),
                    ],
                ]],
                'application_context' => array_filter([
                    'return_url' => $request->returnUrl,
                    'cancel_url' => $request->returnUrl,
                    'user_action' => 'PAY_NOW',
                ]),
            ]);

        if (! $response->successful()) {
            throw PaymentException::chargeFailed(self::PROVIDER, $response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $approve = collect($data['links'] ?? [])
            ->firstWhere('rel', 'approve');

        return new PaymentResult(
            status: PaymentTransaction::STATUS_PENDING,
            providerReference: (string) ($data['id'] ?? ''),
            redirectUrl: is_array($approve) ? ($approve['href'] ?? null) : null,
            raw: $data,
        );
    }

    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool
    {
        $webhookId = (string) $gateway->setting('webhook_id', '');

        if ($webhookId === '') {
            return false;
        }

        $token = $this->accessToken($gateway);

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->base($gateway).'/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => $request->all(),
            ]);

        return $response->successful()
            && ($response->json('verification_status') === 'SUCCESS');
    }

    public function parseWebhook(PaymentGateway $gateway, Request $request): WebhookEvent
    {
        $eventType = (string) $request->input('event_type', '');
        /** @var array<string, mixed> $resource */
        $resource = is_array($request->input('resource')) ? $request->input('resource') : [];

        $status = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.COMPLETED' => PaymentTransaction::STATUS_SUCCEEDED,
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => PaymentTransaction::STATUS_FAILED,
            'PAYMENT.CAPTURE.REFUNDED' => PaymentTransaction::STATUS_REFUNDED,
            'CHECKOUT.ORDER.APPROVED' => PaymentTransaction::STATUS_PENDING,
            default => PaymentTransaction::STATUS_PENDING,
        };

        $purchaseUnit = is_array($resource['purchase_units'][0] ?? null) ? $resource['purchase_units'][0] : [];
        $amount = is_array($resource['amount'] ?? null) ? $resource['amount'] : [];

        return new WebhookEvent(
            provider: self::PROVIDER,
            status: $status,
            providerReference: (string) ($purchaseUnit['payments']['captures'][0]['id'] ?? $resource['id'] ?? '') ?: null,
            orderNumber: (string) ($purchaseUnit['reference_id'] ?? '') ?: null,
            amount: isset($amount['value']) ? (string) $amount['value'] : null,
            currency: strtoupper((string) ($amount['currency_code'] ?? Currency::IDR)),
            raw: $request->all(),
        );
    }

    private function accessToken(PaymentGateway $gateway): string
    {
        $response = Http::withBasicAuth(
            (string) $gateway->credential('client_id', ''),
            (string) $gateway->credential('client_secret', ''),
        )->asForm()->timeout(20)->post($this->base($gateway).'/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        if (! $response->successful()) {
            throw PaymentException::chargeFailed(self::PROVIDER, 'could not obtain an access token');
        }

        return (string) $response->json('access_token', '');
    }

    private function base(PaymentGateway $gateway): string
    {
        return $gateway->isLive()
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
