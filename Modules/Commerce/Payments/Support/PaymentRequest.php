<?php

namespace Modules\Commerce\Payments\Support;

/**
 * WS3 — a gateway-agnostic charge request.
 *
 * Built from a tenant order by the PaymentManager. The gateway reference we ask
 * the provider to echo back is the ORDER NUMBER, so a webhook can be correlated
 * without trusting anything else in the payload.
 */
final class PaymentRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $customerName = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $customerPhone = null,
        public readonly string $returnUrl = '',
        public readonly string $notifyUrl = '',
        public readonly array $metadata = [],
    ) {}
}
