<?php

namespace App\Platform\Access;

use App\Models\PrincipalRoleAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ResolvesRoles;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves effective roles from the central authorization tables (Phase 1B-2A).
 *
 * Only active assignments count: a revoked principal assignment or a revoked /
 * suspended membership (or a suspended tenant) yields no roles.
 */
final class RoleResolver implements ResolvesRoles
{
    public function principalRoleKeys(User $user): array
    {
        return PrincipalRoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->where('status', PrincipalRoleAssignment::STATUS_ACTIVE)
            ->with('role')
            ->get()
            ->map(fn (PrincipalRoleAssignment $assignment) => $assignment->role?->key)
            // Defense in depth: ignore any role that is not platform-scoped, so a
            // cross-scope row inserted outside the guard can never grant access.
            ->filter(fn (?string $key) => $key !== null && RbacCatalog::isPrincipalAssignableRole($key))
            ->unique()
            ->values()
            ->all();
    }

    public function tenantRoleKeys(User $user, string $tenantId): array
    {
        $membership = $this->activeMembershipQuery($user, $tenantId)->first();

        if ($membership === null) {
            return [];
        }

        return $membership->roles
            ->pluck('key')
            // Defense in depth: only tenant/patient roles attached to the
            // membership are ever honoured.
            ->filter(fn (?string $key) => $key !== null && RbacCatalog::isMembershipAssignableRole($key))
            ->unique()
            ->values()
            ->all();
    }

    public function allActiveRoleKeys(User $user): array
    {
        return array_values(array_unique([
            ...$this->principalRoleKeys($user),
            ...$this->membershipRoleKeys($user),
        ]));
    }

    public function activeTenantIds(User $user): array
    {
        return TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn (Builder $query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->pluck('tenant_id')
            ->unique()
            ->values()
            ->all();
    }

    public function permissionKeysForRoles(array $roleKeys): array
    {
        if ($roleKeys === []) {
            return [];
        }

        return Role::query()
            ->whereIn('key', $roleKeys)
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('key'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function membershipRoleKeys(User $user): array
    {
        return TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn (Builder $query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with('roles')
            ->get()
            ->flatMap(fn (TenantMembership $membership) => $membership->roles->pluck('key'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Builder<TenantMembership>
     */
    private function activeMembershipQuery(User $user, string $tenantId): Builder
    {
        return TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn (Builder $query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with('roles');
    }
}
