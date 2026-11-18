<?php

namespace App\Platform\Branches;

use App\Models\User;
use App\Platform\Branches\Contracts\AuthorizesBranchAccess;
use App\Platform\Branches\Contracts\VerifiesBranchScope;

/**
 * Trusted branch-scope verification (Phase 2B).
 *
 * Proves the chain user -> tenant -> branch from trusted data, never from client
 * input: the identity is an active member of the verified tenant AND the branch
 * is an active branch of that tenant the identity may access. The underlying
 * authorizer only reads the tenant database while it is bound for the verified
 * tenant, so a branch entitlement can never authorize another tenant or another
 * branch.
 */
final class TenantBranchScope implements VerifiesBranchScope
{
    public function __construct(
        private readonly AuthorizesBranchAccess $branches,
    ) {}

    public function isBranchPermitted(?User $user, string $tenantId, string $branchId): bool
    {
        if ($user === null || $tenantId === '' || $branchId === '') {
            return false;
        }

        return $this->branches->canAccessBranch($user, $tenantId, $branchId);
    }
}
