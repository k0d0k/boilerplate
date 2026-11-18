<?php

namespace Modules\Commerce\Payments\Drivers;

use App\Models\PaymentGateway;
use Illuminate\Http\Client\PendingRequest;
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
 * WS3 — Midtrans (Snap) adapter.
 *
 * Sandbox and live share the same API shape and differ only by host, so the
 * mode is read from the gateway row on every call. Amounts are sent in the
 * currency's minor unit (IDR as a whole number, MYR to two decimals).
 *
 * Webhook authenticity follows Midtrans's `signature_key`
 * (`sha512(order_id + status_code + gross_amount + server_key)`), compared in
 * constant time.
 */
final class MidtransDriver implements PaymentGatewayDriver
{
    public const PROVIDER = 'midtrans';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function requiredCredentials(): array
    {
        return ['server_key'];
    }

    public function isConfigured(PaymentGateway $gateway): bool
    {
        return $gateway->isConfigured($this->requiredCredentials());
    }

    public function charge(PaymentGateway $gateway, PaymentRequest $request): PaymentResult
    {
        $payload = [
            'transaction_details' => [
                'order_id' => $request->orderNumber,
                'gross_amount' => $this->amount($request->currency, $request->amount),
            ],
            'customer_details' => array_filter([
                'first_name' => $request->customerName,
                'email' => $request->customerEmail,
                'phone' => $request->customerPhone,
            ]),
            'callbacks' => array_filter([
                'finish' => $request->returnUrl,
            ]),
        ];

        $response = $this->client($gateway)->post($this->base($gateway).'/snap/v1/transactions', $payload);

        if (! $response->successful()) {
            throw PaymentException::chargeFailed(self::PROVIDER, $response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        return new PaymentResult(
            status: PaymentTransaction::STATUS_PENDING,
            providerReference: $request->orderNumber,
            redirectUrl: $data['redirect_url'] ?? null,
            raw: $data,
        );
    }

    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool
    {
        $serverKey = (string) $gateway->credential('server_key', '');

        if ($serverKey === '') {
            return false;
        }

        $expected = hash('sha512',
            (string) $request->input('order_id').
            (string) $request->input('status_code').
            (string) $request->input('gross_amount').
            $serverKey
        );

        return hash_equals($expected, (string) $request->input('signature_key', ''));
    }

    public function parseWebhook(PaymentGateway $gateway, Request $request): WebhookEvent
    {
        $transactionStatus = (string) $request->input('transaction_status', '');
        $fraudStatus = (string) $request->input('fraud_status', '');

        $status = match ($transactionStatus) {
            'capture' => $fraudStatus === 'accept' ? PaymentTransaction::STATUS_SUCCEEDED : PaymentTransaction::STATUS_PENDING,
            'settlement' => PaymentTransaction::STATUS_SUCCEEDED,
            'pending' => PaymentTransaction::STATUS_PENDING,
            'deny', 'cancel', 'failure' => PaymentTransaction::STATUS_FAILED,
            'expire' => PaymentTransaction::STATUS_EXPIRED,
            'refund', 'partial_refund' => PaymentTransaction::STATUS_REFUNDED,
            default => PaymentTransaction::STATUS_PENDING,
        };

        return new WebhookEvent(
            provider: self::PROVIDER,
            status: $status,
            providerReference: (string) $request->input('order_id', '') ?: null,
            orderNumber: (string) $request->input('order_id', '') ?: null,
            amount: $request->input('gross_amount') !== null ? (string) $request->input('gross_amount') : null,
            currency: strtoupper((string) ($request->input('currency') ?: Currency::IDR)),
            raw: $request->all(),
        );
    }

    private function client(PaymentGateway $gateway): PendingRequest
    {
        return Http::withBasicAuth((string) $gateway->credential('server_key', ''), '')
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    private function base(PaymentGateway $gateway): string
    {
        return $gateway->isLive()
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    private function amount(string $currency, string $amount): int|float
    {
        return $currency === Currency::IDR
            ? (int) round((float) $amount)
            : (float) Currency::round((float) $amount, $currency);
    }
}
