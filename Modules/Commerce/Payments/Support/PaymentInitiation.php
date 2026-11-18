<?php

namespace Modules\Commerce\Payments\Support;

use App\Models\PaymentIntent;

/**
 * WS3 — the result of initiating a gateway charge.
 *
 * Carries the persisted central intent (the webhook routing index) plus the
 * hosted redirect URL the buyer must follow to complete payment.
 */
final class PaymentInitiation
{
    public function __construct(
        public readonly PaymentIntent $intent,
        public readonly ?string $redirectUrl = null,
    ) {}
}
