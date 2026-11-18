<?php

namespace App\Platform\Branches\Contracts;

use App\Models\User;

/**
 * Trusted branch-scope verification for the FeatureGate (Phase 2B).
 *
 * A branch id is never proof of authorization: branch-scoped module access is
 * only honoured after an *explicitly verified* chain — the identity is an active
 * member of the verified tenant AND the branch is an active branch of THAT
 * tenant the identity may access. A branch entitlement therefore can never
 * authorize another tenant or another branch.
 *
 * Implementations must fail closed: an unknown/foreign branch, a suspended
 * branch, a non-member identity or a tenant database that is not the one being
 * verified all return `false`.
 */
interface VerifiesBranchScope
{
    /**
     * True only when the identity, the verified tenant and the branch form a
     * relationship the platform can prove from trusted data.
     */
    public function isBranchPermitted(?User $user, string $tenantId, string $branchId): bool;
}
