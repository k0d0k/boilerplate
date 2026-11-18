<?php

namespace App\Platform\Access\Contracts;

use App\Models\User;

/**
 * Scope validation (Phase 1B-2A).
 *
 * Guards against passing arbitrary tenant ids around: a scope id is only valid
 * when the identity actually holds an eligible membership for it. This is the
 * hook a future context switcher must call before accepting a requested tenant,
 * so unauthorized or made-up ids are rejected rather than silently honoured.
 */
interface ValidatesTenantScope
{
    /**
     * True only when a well-formed tenant id is one the identity may operate in.
     */
    public function isValidFor(User $user, ?string $tenantId): bool;
}
