<?php

namespace App\Platform\Access;

use App\Models\Brand;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\VerifiesBrandScope;

/**
 * Central-table brand-scope verification (Phase 1B-4R).
 *
 * Proves the full user -> tenant -> brand chain from trusted data, never from
 * client input:
 *
 *   1. the identity holds an active membership in the verified tenant;
 *   2. the tenant is actually linked to the brand through `tenant_brands`;
 *   3. the brand exists and is active.
 *
 * Any leg that cannot be proven returns `false`, so an unknown, foreign,
 * suspended or fabricated brand id denies brand-scoped access by default.
 */
final class TenantBrandScope implements VerifiesBrandScope
{
    public function __construct(
        private readonly AuthorizesTenantMembership $memberships,
    ) {}

    public function isBrandPermitted(?User $user, string $tenantId, string $brandId): bool
    {
        if ($user === null || $tenantId === '' || $brandId === '') {
            return false;
        }

        if (! $this->memberships->isMemberOf($user, $tenantId)) {
            return false;
        }

        $brand = Brand::query()->whereKey($brandId)->first();

        if ($brand === null || ! $brand->isActive()) {
            return false;
        }

        return $brand->tenants()->whereKey($tenantId)->exists();
    }
}
