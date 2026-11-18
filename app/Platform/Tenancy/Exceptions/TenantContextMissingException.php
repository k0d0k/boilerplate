<?php

namespace App\Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a tenant-owned model is used without an active tenant context
 * (Phase 2A). Fail closed: never fall back to the central connection or to
 * another tenant's database.
 */
final class TenantContextMissingException extends RuntimeException
{
    public static function noActiveTenant(): self
    {
        return new self(
            'No tenant database context is active; tenant-owned models may only be used inside a verified tenant context.',
        );
    }
}
