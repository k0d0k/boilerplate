<?php

namespace App\Platform\Branches\Contracts;

use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;

/**
 * Verified branch authorization (Phase 2B).
 *
 * Decides which branches of a tenant's isolated database an identity may access,
 * and whether it may administer branches at all. Every decision is re-derived
 * from trusted data — the central membership/role tables for the identity and
 * the tenant database for the branches/assignments — never from a client value.
 *
 * A read only ever touches the requested tenant's OWN database, and only when
 * the identity is an active member of it. When that database is not the one
 * currently bound (e.g. a Livewire update request, which bypasses the panel's
 * tenant-binding middleware), the implementation binds it for the duration of
 * the read and releases it again, so a branch read can never cross into another
 * tenant's database or leak a connection.
 *
 * Guarantees every implementation must keep:
 *  - only ACTIVE branches of the requested tenant are ever returned;
 *  - a non-manager only reaches branches it is explicitly assigned to;
 *  - a management role (RBAC `branch.operations.manage`) may administer every
 *    active branch of the tenant;
 *  - a non-member, or an unknown/suspended tenant, is fail-closed
 *    (empty / false) and never triggers a database read.
 */
interface AuthorizesBranchAccess
{
    /**
     * True only when the identity is an active member of the tenant AND its
     * tenant role grants the branch-administration permission.
     */
    public function canManageBranches(User $user, string $tenantId): bool;

    /**
     * The active branches the identity may access in the tenant, ordered by
     * branch name. Managers receive every active branch; other staff receive
     * only the active branches they are actively assigned to.
     *
     * @return list<TenantBranch>
     */
    public function accessibleBranches(User $user, string $tenantId): array;

    /**
     * Ids of the active branches the identity may access in the tenant, ordered
     * by branch name. Managers receive every active branch; other staff receive
     * only the active branches they are actively assigned to.
     *
     * @return list<string>
     */
    public function accessibleBranchIds(User $user, string $tenantId): array;

    /**
     * True only when the branch is an active branch of the tenant the identity
     * may access (manager or active assignee).
     */
    public function canAccessBranch(User $user, string $tenantId, string $branchId): bool;
}
