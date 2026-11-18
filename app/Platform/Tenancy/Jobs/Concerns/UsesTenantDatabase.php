<?php

namespace App\Platform\Tenancy\Jobs\Concerns;

use App\Platform\Tenancy\Jobs\Middleware\TenantDatabaseContext;

/**
 * Gives a queue job a verified tenant database context (Phase 2A).
 *
 * A job records the tenant it belongs to with `forTenant()`; the returned
 * middleware binds that tenant's isolated database for the job and always
 * releases it afterwards, so jobs for different tenants can never share a
 * connection.
 *
 * Example:
 *
 *     $tenantId = $tenant->getKey();
 *     MyTenantJob::dispatch()->forTenant($tenantId);
 */
trait UsesTenantDatabase
{
    protected ?string $tenantId = null;

    public function forTenant(string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new TenantDatabaseContext($this->tenantId)];
    }
}
