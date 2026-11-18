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
 * WS3 — iPaymu adapter.
 *
 * Requests are signed with an HMAC-SHA256 over
 * `METHOD:VA:sha256(jsonBody):apiKey` (base64), sent as the `signature` header
 * alongside the `va` header. The transaction's `referenceId` is our order
 * number, so a notify callback correlates without extra lookup keys.
 *
 * Webhook authenticity follows iPaymu's notify signature
 * (`sha256(va + apiKey + referenceId + status + statusCode)`), compared in
 * constant time.
 */
final class IpaymuDriver implements PaymentGatewayDriver
{
    public const PROVIDER = 'ipaymu';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function requiredCredentials(): array
    {
        return ['api_key', 'va'];
    }

    public function isConfigured(PaymentGateway $gateway): bool
    {
        return $gateway->isConfigured($this->requiredCredentials());
    }

    public function charge(PaymentGateway $gateway, PaymentRequest $request): PaymentResult
    {
        $amount = (int) round((float) $request->amount);

        $body = array_filter([
            'product' => [$request->orderNumber],
            'qty' => [1],
            'price' => [$amount],
            'amount' => $amount,
            'returnUrl' => $request->returnUrl,
            'notifyUrl' => $request->notifyUrl,
            'referenceId' => $request->orderNumber,
            'buyerName' => $request->customerName,
            'buyerEmail' => $request->customerEmail,
            'buyerPhone' => $request->customerPhone,
        ], fn ($value): bool => $value !== null && $value !== '');

        $response = $this->client($gateway, $body)
            ->post($this->base($gateway).'/api/v2/payment', $body);

        if (! $response->successful()) {
            throw PaymentException::chargeFailed(self::PROVIDER, $response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $payload = is_array($data['Data'] ?? null) ? $data['Data'] : [];

        return new PaymentResult(
            status: PaymentTransaction::STATUS_PENDING,
            providerReference: (string) ($payload['SessionID'] ?? $request->orderNumber),
            redirectUrl: $payload['Url'] ?? null,
            raw: $data,
        );
    }

    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool
    {
        $apiKey = (string) $gateway->credential('api_key', '');
        $va = (string) $gateway->credential('va', '');

        if ($apiKey === '' || $va === '') {
            return false;
        }

        $expected = hash('sha256',
            $va.
            $apiKey.
            (string) $request->input('referenceId').
            (string) $request->input('status').
            (string) $request->input('statusCode')
        );

        return hash_equals($expected, (string) $request->input('signature', ''));
    }

    public function parseWebhook(PaymentGateway $gateway, Request $request): WebhookEvent
    {
        $statusCode = (string) $request->input('statusCode', '');
        $status = strtolower((string) $request->input('status', ''));

        $mapped = match (true) {
            $statusCode === '200' || in_array($status, ['berhasil', 'success', 'paid', 'settlement'], true) => PaymentTransaction::STATUS_SUCCEEDED,
            in_array($status, ['pending', 'unpaid', 'menunggu'], true) => PaymentTransaction::STATUS_PENDING,
            in_array($status, ['expired', 'kadaluarsa'], true) => PaymentTransaction::STATUS_EXPIRED,
            in_array($status, ['gagal', 'failed', 'berhasil_direfund'], true) => PaymentTransaction::STATUS_FAILED,
            default => PaymentTransaction::STATUS_PENDING,
        };

        return new WebhookEvent(
            provider: self::PROVIDER,
            status: $mapped,
            providerReference: (string) ($request->input('sid') ?? $request->input('trx_id') ?? '') ?: null,
            orderNumber: (string) $request->input('referenceId', '') ?: null,
            amount: $request->input('amount') !== null ? (string) $request->input('amount') : null,
            currency: strtoupper((string) ($request->input('currency') ?: Currency::IDR)),
            raw: $request->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function client(PaymentGateway $gateway, array $body): PendingRequest
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}';

        $stringToSign = 'POST:'.(string) $gateway->credential('va', '').':'.hash('sha256', $json).':'.(string) $gateway->credential('api_key', '');
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, (string) $gateway->credential('api_key', ''), true));

        return Http::withHeaders([
            'va' => (string) $gateway->credential('va', ''),
            'signature' => $signature,
        ])->acceptJson()->asJson()->timeout(20);
    }

    private function base(PaymentGateway $gateway): string
    {
        return $gateway->isLive()
            ? 'https://my.ipaymu.com'
            : 'https://sandbox.ipaymu.com';
    }
}
