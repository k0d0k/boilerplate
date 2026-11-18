<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Contracts\ResolvesRoles;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;

/**
 * Partner governance (Phase 1B-2A, v3.3).
 *
 * Reading, creating and editing a partner is open to any principal holding the
 * partner capability. DELETION is deliberately narrower: only an active
 * Principal Super Admin may remove a partner, because removal stops a whole
 * organisation's access at once.
 *
 * Removal is a SOFT delete (v3.3): the central row is flagged removed — so
 * access stops immediately — while the partner's central child rows and, in
 * particular, its `tenant_databases` registry row are preserved, keeping the
 * physical database name claimed so a retained clinical schema can never be
 * re-opened by a new partner. The Filament `TenantResource` hard-returns false
 * from `canDelete()`: only the TailAdmin action is wired to `delete` here.
 */
final class TenantPolicy
{
    public function __construct(
        private readonly AuthorizesPrincipal $principal,
        private readonly ResolvesRoles $roles,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->principal->can($user, PermissionKey::PLATFORM_TENANT_MANAGE);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $this->deleteAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_active
            && in_array(RoleKey::PRINCIPAL_SUPER_ADMIN, $this->roles->principalRoleKeys($user), true);
    }
}
