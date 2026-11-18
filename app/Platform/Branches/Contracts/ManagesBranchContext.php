<?php

namespace App\Platform\Branches\Contracts;

use App\Models\User;
use App\Platform\Branches\BranchOption;
use App\Platform\Branches\Models\TenantBranch;

/**
 * Secure branch context resolution (Phase 2B).
 *
 * The context is the *verified* branch an authenticated Clinic identity is
 * currently operating in, always inside the selected tenant's isolated
 * database. Resolution rules mirror the tenant context:
 *  1. eligibility is re-derived on every call — an active branch the identity
 *     may access (manager role, or an active assignment) whose tenant is the
 *     verified current tenant;
 *  2. a stored selection is honoured only after it is revalidated, and is
 *     cleared the moment it fails (tenant switch, revoked assignment, suspended
 *     or deleted branch);
 *  3. exactly one accessible branch is auto-selected after verification; with
 *     several, an explicit selection is required; with none there is no branch
 *     context (branch-scoped business operations are denied).
 *
 * A branch id supplied by the browser is never trusted as authority.
 */
interface ManagesBranchContext
{
    /**
     * Every branch the identity may switch into in the current tenant.
     *
     * @return list<BranchOption>
     */
    public function eligibleOptions(User $user): array;

    public function hasEligibleBranch(User $user): bool;

    public function hasMultipleEligibleBranches(User $user): bool;

    /**
     * The verified branch of the current context, or null when none applies.
     */
    public function currentBranch(User $user): ?TenantBranch;

    /**
     * The verified branch id of the current context, or null when none applies.
     * Revalidates the stored selection on every call and auto-selects the single
     * accessible branch after verification.
     */
    public function currentBranchId(User $user): ?string;

    public function hasBranchContext(User $user): bool;

    /**
     * True when a choice is outstanding: several accessible branches and no
     * verified selection yet.
     */
    public function requiresExplicitSelection(User $user): bool;

    /**
     * True when the identity may administer branches in the current tenant
     * (used to offer the first-branch setup flow when none exist yet).
     */
    public function canManageBranches(User $user): bool;

    /**
     * Verify and persist an explicit selection. Returns false (and clears any
     * stale value) when the branch is malformed, foreign, suspended, or not
     * accessible to the identity.
     */
    public function select(User $user, ?string $branchId): bool;

    /**
     * True when the given branch id is the current verified context.
     */
    public function matchesCurrent(User $user, ?string $branchId): bool;

    /**
     * Forget the stored selection (tenant switch, logout, revocation).
     */
    public function clear(): void;
}
