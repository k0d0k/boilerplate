<?php

namespace App\Platform\Access\Contracts;

use App\Models\User;

/**
 * Resolves the effective role assignments of an identity (Phase 1B-2A).
 *
 * Roles are resolved from the central authorization tables only:
 *  - platform roles from principal role assignments;
 *  - tenant roles from tenant memberships.
 *
 * Revoked / suspended / non-active assignments are never returned, so callers
 * inherit fail-closed behaviour.
 */
interface ResolvesRoles
{
    /**
     * Active platform (principal) role keys held by the identity.
     *
     * @return list<string>
     */
    public function principalRoleKeys(User $user): array;

    /**
     * Active role keys held through the identity's membership in a tenant.
     *
     * @return list<string>
     */
    public function tenantRoleKeys(User $user, string $tenantId): array;

    /**
     * All active role keys (platform + every tenant membership).
     *
     * @return list<string>
     */
    public function allActiveRoleKeys(User $user): array;

    /**
     * Tenant ids where the identity holds an active membership.
     *
     * @return list<string>
     */
    public function activeTenantIds(User $user): array;

    /**
     * Distinct permission keys granted by the given role keys, resolved from the
     * database so the catalog tables remain authoritative at request time.
     *
     * @param  list<string>  $roleKeys
     * @return list<string>
     */
    public function permissionKeysForRoles(array $roleKeys): array;
}
