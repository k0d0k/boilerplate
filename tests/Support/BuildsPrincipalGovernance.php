<?php

namespace Tests\Support;

use App\Models\User;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\RoleKey;

/**
 * Helpers for building Phase 7D principal (platform governance) identities.
 *
 * A principal identity needs BOTH the provisional legacy `role` string (which
 * routes it to the Principal panel — see {@see PanelAccessPolicy})
 * AND a real principal role assignment (which is what the resource authorization
 * actually reads). This trait wires both so a test never accidentally exercises
 * a principal that can reach the panel but holds no permissions, or vice versa.
 *
 * Depends on {@see BuildsAccess::grantPrincipalRole()}.
 */
trait BuildsPrincipalGovernance
{
    /**
     * An active principal holding one principal role assignment.
     */
    protected function principal(string $roleKey = RoleKey::PRINCIPAL_SUPER_ADMIN): User
    {
        $user = User::factory()->role($roleKey)->create();

        $this->grantPrincipalRole($user, $roleKey);

        return $user;
    }
}
