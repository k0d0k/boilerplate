<?php

namespace App\Http\Controllers\Api\Payments;

use App\Models\PaymentIntent;
use App\Platform\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;

/**
 * WS3 — the gateway-agnostic payment webhook receiver.
 *
 * One public, unauthenticated endpoint per provider
 * (`POST api/v1/payments/{provider}/webhook`). A webhook arrives with NO tenant
 * context, so authority comes from two things only: the gateway's signature
 * (verified by the driver) and the central {@see PaymentIntent}
 * registry, which resolves the originating tenant so the order can be settled
 * there. The handler is idempotent — a replayed notification is a no-op — and
 * the response is a minimal acknowledgement that never echoes the payload or a
 * credential.
 */
final class WebhookController
{
    public function __construct(private readonly PaymentManager $payments) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $intent = $this->payments->handleWebhook($provider, $request);
        } catch (PaymentException) {
            // An unrecognised, mis-signature or unresolvable notification is
            // rejected with a generic message (no provider internals leak).
            return ApiResponse::error('webhook_rejected', 'The payment notification could not be processed.', 400);
        }

        return ApiResponse::ok([
            'provider' => $provider,
            'reference' => $intent->provider_reference,
            'status' => $intent->status,
        ]);
    }
}
