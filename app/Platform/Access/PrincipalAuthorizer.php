<?php

namespace App\Platform\Access;

use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Contracts\ResolvesRoles;

/**
 * Principal / platform governance authorization (Phase 1B-2A).
 *
 * Platform permissions are decided only from principal role assignments. A
 * principal role never grants tenant or clinical permissions, and a
 * non-platform permission is always denied here.
 */
final class PrincipalAuthorizer implements AuthorizesPrincipal
{
    public function __construct(private readonly ResolvesRoles $roles) {}

    public function isPrincipal(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $this->roles->principalRoleKeys($user) !== [];
    }

    public function can(User $user, string $permission): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (RbacCatalog::permissionScope($permission) !== RbacCatalog::SCOPE_PLATFORM) {
            return false;
        }

        $roleKeys = $this->roles->principalRoleKeys($user);

        if ($roleKeys === []) {
            return false;
        }

        return in_array($permission, $this->roles->permissionKeysForRoles($roleKeys), true);
    }
}
