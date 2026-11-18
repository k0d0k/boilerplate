<?php

namespace App\Platform\FeatureGate;

use DateTimeInterface;

/**
 * A verified entitlement decision for one module at one scope (Phase 1B-4).
 *
 * Only an explicit `granted: true` counts. No row at all resolves to `null` and
 * is treated as "not entitled" — the fail-closed default.
 */
final class EntitlementGrant
{
    public function __construct(
        public readonly bool $granted,
        public readonly bool $subscriptionRequired = false,
        public readonly ?string $subscriptionReference = null,
        public readonly ?DateTimeInterface $expiresAt = null,
    ) {}
}
