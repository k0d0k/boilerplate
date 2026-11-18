<?php

namespace App\Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a tenant resolves to a physical database that conflicts with the
 * central `tenant_databases` registry (the central database, a database
 * registered for another tenant, or a different name than the one already
 * recorded for this tenant). Fail closed so the operator can inspect the
 * database and the registry before continuing.
 */
final class TenantDatabaseConflictException extends RuntimeException
{
    public static function for(string $tenantId, string $reason): self
    {
        return new self(
            "Tenant [{$tenantId}] database configuration conflict: [{$reason}]. "
            .'Inspect the target database and the central `tenant_databases` registry before continuing.',
        );
    }
}
