<?php

namespace App\Platform\Access\Contracts;

use App\Models\User;

/**
 * Principal / platform governance authorization (Phase 1B-2A).
 *
 * Platform permissions are granted only through principal role assignments; a
 * principal role never grants clinical/tenant data access (DECISIONS.md ARCH-06,
 * ROLE_PERMISSION_MATRIX.md).
 */
interface AuthorizesPrincipal
{
    public function isPrincipal(User $user): bool;

    /**
     * True only when the identity holds an active principal role that grants the
     * given platform-scoped permission.
     */
    public function can(User $user, string $permission): bool;
}
