<?php

namespace App\Platform\Access\Contracts;

use App\Models\TenantMembership;
use App\Models\User;

/**
 * Tenant membership authorization (Phase 1B-2A).
 *
 * A membership is eligible only when it is `active` AND its tenant is active.
 * Unknown, revoked, suspended, or tenant-mismatched memberships are denied.
 * A role granted in Tenant A must never authorize Tenant B.
 */
interface AuthorizesTenantMembership
{
    /**
     * The identity's active membership in the tenant, or null when none applies.
     */
    public function membershipFor(User $user, string $tenantId): ?TenantMembership;

    public function isMemberOf(User $user, string $tenantId): bool;

    /**
     * True only when the active membership in the given tenant grants the
     * permission. Cross-tenant roles can never satisfy this.
     */
    public function can(User $user, string $tenantId, string $permission): bool;
}
