<?php

namespace Modules\Consultations\Support;

use Modules\Consultations\Services\ConsultationTariffService;

/**
 * Workstream 2 — the configured tariff for one consultation channel.
 *
 * A value object only: it carries the resolved amount and its currency and
 * never formats or authorizes anything. The amount is held in MAJOR units
 * exactly as the business owner configured it, so a display layer applies the
 * currency's own rounding/symbol at the presentation boundary.
 *
 * There is deliberately no default: an unset tariff resolves to `null` (see
 * {@see ConsultationTariffService}), so the
 * platform never fabricates a price.
 */
final readonly class ConsultationTariff
{
    public function __construct(
        public string $channel,
        public float $amount,
        public string $currency,
    ) {}
}
