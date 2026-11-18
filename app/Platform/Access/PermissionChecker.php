<?php

namespace App\Platform\Access;

use App\Models\User;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ResolvesRoles;

/**
 * DENY-BY-DEFAULT permission checker (Phase 1B-2A).
 *
 * Decision rules:
 *  - unknown / inactive identity            => denied
 *  - unknown permission key                 => denied
 *  - platform permission                     => only active principal roles may grant
 *  - tenant / patient permission            => only an active membership in the
 *                                              selected tenant may grant
 *  - no selected tenant context with
 *    0 or >1 eligible memberships           => denied (fail closed, never pick
 *                                              an arbitrary tenant)
 *
 * Because a membership only ever exposes the roles attached to that membership,
 * a role held in Tenant A can never authorize Tenant B.
 */
final class PermissionChecker implements ChecksPermissions
{
    public function __construct(private readonly ResolvesRoles $roles) {}

    public function allows(?User $user, string $permission, ?AccessContext $context = null): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }

        if (! in_array($permission, PermissionKey::ALL, true)) {
            return false;
        }

        $scope = RbacCatalog::permissionScope($permission);

        if ($scope === RbacCatalog::SCOPE_PLATFORM) {
            return $this->rolesGrant($this->roles->principalRoleKeys($user), $permission);
        }

        if ($scope !== RbacCatalog::SCOPE_TENANT && $scope !== RbacCatalog::SCOPE_PATIENT) {
            return false;
        }

        $tenantId = $context?->tenantId;

        if ($tenantId === null || $tenantId === '') {
            $eligible = $this->roles->activeTenantIds($user);

            if (count($eligible) !== 1) {
                return false;
            }

            $tenantId = $eligible[0];
        }

        return $this->rolesGrant($this->roles->tenantRoleKeys($user, $tenantId), $permission);
    }

    public function denies(?User $user, string $permission, ?AccessContext $context = null): bool
    {
        return ! $this->allows($user, $permission, $context);
    }

    /**
     * @param  list<string>  $roleKeys
     */
    private function rolesGrant(array $roleKeys, string $permission): bool
    {
        if ($roleKeys === []) {
            return false;
        }

        return in_array($permission, $this->roles->permissionKeysForRoles($roleKeys), true);
    }
}
