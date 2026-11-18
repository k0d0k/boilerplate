<?php

namespace App\Platform\Access\Contracts;

use App\Models\User;
use App\Platform\Access\AccessContext;

/**
 * Central permission decision point (Phase 1B-2A). DENY BY DEFAULT.
 *
 * A decision is positive only when an active, correctly-scoped assignment — a
 * principal role for platform permissions, an active tenant membership role for
 * tenant/patient permissions — explicitly grants the permission. Everything else
 * (unknown user, suspended user, unknown permission, revoked/suspended/mismatched
 * membership, ambiguous multi-tenant context) resolves to `false`.
 */
interface ChecksPermissions
{
    public function allows(?User $user, string $permission, ?AccessContext $context = null): bool;

    public function denies(?User $user, string $permission, ?AccessContext $context = null): bool;
}
