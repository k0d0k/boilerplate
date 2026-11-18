<?php

namespace App\Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a tenant database connection is requested for a tenant that is
 * unknown, suspended or otherwise not eligible (Phase 2A). Fail closed.
 */
final class TenantAccessDeniedException extends RuntimeException
{
    public static function forTenant(string $tenantId): self
    {
        return new self("Tenant [{$tenantId}] is not eligible for a database connection.");
    }
}
