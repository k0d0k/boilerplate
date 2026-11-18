<?php

namespace Modules\Commerce\Payments\Support;

use Modules\Commerce\Models\PaymentTransaction;

/**
 * WS3 — a gateway-agnostic webhook event.
 *
 * Every driver normalises its own webhook shape into this object, so the
 * settlement path is identical across providers. `status` uses the canonical
 * {@see PaymentTransaction} status vocabulary.
 */
final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $orderNumber = null,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly array $raw = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === PaymentTransaction::STATUS_SUCCEEDED;
    }
}
