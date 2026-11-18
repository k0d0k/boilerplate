<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Contracts\ResolvesRoles;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;

/**
 * Brand governance (Phase 1B-2A, v3.3).
 *
 * Reading and editing a brand is open to any principal holding the brand
 * capability. DELETION is deliberately narrower: only an active Principal
 * Super Admin may remove a brand, because a brand is a shared commercial
 * identity (ARCH-05) whose removal has to be weighed against every partner
 * still linked to it.
 *
 * The Filament `BrandResource` does NOT consult this policy for `canDelete()`:
 * it hard-returns false so the interim Filament surface stays non-destructive.
 * Only the TailAdmin "Master Brand" action is wired to `delete` here.
 */
final class BrandPolicy
{
    public function __construct(
        private readonly AuthorizesPrincipal $principal,
        private readonly ResolvesRoles $roles,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->principal->can($user, PermissionKey::PLATFORM_BRAND_MANAGE);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->deleteAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_active
            && in_array(RoleKey::PRINCIPAL_SUPER_ADMIN, $this->roles->principalRoleKeys($user), true);
    }
}
