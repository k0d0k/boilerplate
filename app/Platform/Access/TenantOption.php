<?php

namespace App\Platform\Access;

/**
 * Phase 1B-2B — a tenant a staff identity may switch into.
 *
 * This is a *presentation* projection of one eligible membership, not an
 * authorization decision: `id` is the verified central tenant id, `name` is the
 * tenant's display name and `brandLabel` is the tenant's primary brand name
 * (a commercial label only — a brand is never a tenant and never a branch,
 * ARCH-05). Branch and brand are deliberately NOT authorization scopes here:
 * the switcher carries the tenant only, and every eligibility check re-reads
 * the central membership tables.
 */
final class TenantOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $brandLabel = null,
    ) {}

    /**
     * @return array{id: string, name: string, brand: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brandLabel,
        ];
    }
}
