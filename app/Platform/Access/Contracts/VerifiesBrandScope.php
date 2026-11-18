<?php

namespace App\Platform\Access\Contracts;

use App\Models\User;

/**
 * Explicit brand-scope verification (Phase 1B-4R).
 *
 * A brand is a commercial identity distinct from a tenant and a branch
 * (ARCH-05). A nonempty brand id is never proof of authorization: brand-scoped
 * module access is only ever honoured after an *explicitly verified* chain —
 * the identity is an active member of the verified tenant AND that tenant is
 * actually linked to the brand AND the brand is active.
 *
 * Implementations must fail closed: any unproven link (unknown brand, foreign
 * brand, suspended brand, non-member identity, empty ids) returns `false`.
 */
interface VerifiesBrandScope
{
    /**
     * True only when the identity, the verified tenant and the brand form a
     * relationship the platform can prove from trusted data.
     */
    public function isBrandPermitted(?User $user, string $tenantId, string $brandId): bool;
}
