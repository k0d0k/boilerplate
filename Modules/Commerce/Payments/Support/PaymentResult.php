<?php

namespace Modules\Commerce\Payments\Support;

use Modules\Commerce\Models\PaymentTransaction;

/**
 * WS3 — the outcome of creating a charge with a gateway.
 *
 * `status` uses the canonical {@see PaymentTransaction} status vocabulary, so a
 * freshly created hosted charge (which is not yet paid) is `pending`.
 * `redirectUrl` is where the buyer is sent to complete payment.
 */
final class PaymentResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly array $raw = [],
    ) {}
}
