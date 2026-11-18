<?php

namespace App\Platform\Access\Exceptions;

use RuntimeException;

/**
 * Thrown when a role is assigned through the wrong channel or has an invalid
 * scope (Phase 1B-2A), e.g. a tenant role attached as a principal assignment or
 * the machine-only `ai_service` role attached to any human assignment.
 */
class InvalidRoleAssignmentException extends RuntimeException
{
    public static function forPrincipal(string $roleKey): self
    {
        return new self(
            "Role [{$roleKey}] cannot be assigned as a principal role: only platform roles are permitted.",
        );
    }

    public static function forMembership(string $roleKey): self
    {
        return new self(
            "Role [{$roleKey}] cannot be assigned through a tenant membership: only tenant/patient roles are permitted.",
        );
    }

    public static function unknown(string $roleId): self
    {
        return new self(
            "Role [{$roleId}] does not exist in the authorization catalog and cannot be assigned.",
        );
    }
}
