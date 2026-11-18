<?php

namespace App\Platform\Access;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ChecksPermissions;

/**
 * Tenant membership authorization (Phase 1B-2A).
 *
 * A membership is eligible only when it is `active`, belongs to the identity and
 * whose tenant is active. Revoked, suspended, unknown and tenant-mismatched
 * memberships are all denied.
 */
final class TenantMembershipAuthorizer implements AuthorizesTenantMembership
{
    public function __construct(private readonly ChecksPermissions $permissions) {}

    public function membershipFor(User $user, string $tenantId): ?TenantMembership
    {
        if (! $user->is_active || $tenantId === '') {
            return null;
        }

        return TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->first();
    }

    public function isMemberOf(User $user, string $tenantId): bool
    {
        return $this->membershipFor($user, $tenantId) !== null;
    }

    public function can(User $user, string $tenantId, string $permission): bool
    {
        if (! $this->isMemberOf($user, $tenantId)) {
            return false;
        }

        return $this->permissions->allows($user, $permission, AccessContext::forTenant($tenantId));
    }
}
