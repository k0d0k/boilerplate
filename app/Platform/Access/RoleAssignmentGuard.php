<?php

namespace App\Platform\Access;

use App\Models\Role;
use App\Platform\Access\Exceptions\InvalidRoleAssignmentException;

/**
 * Enforces strict role scope for privileged assignments (Phase 1B-2A).
 *
 * Server-side and channel-aware: a principal/platform assignment must reference a
 * platform role only, and a tenant membership may only carry tenant/patient roles.
 * The machine-only `ai_service` role and any unknown role are rejected on both
 * channels. This is enforced at the model layer, never by UI filtering alone.
 */
final class RoleAssignmentGuard
{
    public static function assertPrincipalAssignable(?string $roleId): void
    {
        $roleKey = self::roleKey($roleId);

        if (! RbacCatalog::isPrincipalAssignableRole($roleKey)) {
            throw InvalidRoleAssignmentException::forPrincipal($roleKey);
        }
    }

    public static function assertMembershipAssignable(?string $roleId): void
    {
        $roleKey = self::roleKey($roleId);

        if (! RbacCatalog::isMembershipAssignableRole($roleKey)) {
            throw InvalidRoleAssignmentException::forMembership($roleKey);
        }
    }

    private static function roleKey(?string $roleId): string
    {
        $roleId = $roleId ?? '';

        $roleKey = $roleId === ''
            ? null
            : Role::query()->whereKey($roleId)->value('key');

        if ($roleKey === null || ! in_array($roleKey, RoleKey::ALL, true)) {
            throw InvalidRoleAssignmentException::unknown($roleId);
        }

        return $roleKey;
    }
}
