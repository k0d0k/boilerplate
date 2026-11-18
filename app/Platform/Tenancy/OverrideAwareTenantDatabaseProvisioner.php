<?php

namespace App\Platform\Tenancy;

use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;

/**
 * Routes provisioning for any tenant whose database is EXPLICITLY named
 * through the MANUAL, verify-only adapter.
 *
 * Two sources name a tenant's database explicitly: the per-partner manual name
 * recorded in the Principal app (`tenant_databases.configured_database_name`,
 * v3.3) and the legacy single-tenant `.env` override (`TENANT_DB` +
 * `TENANT_DB_TENANT_ID`). Both describe a database created BY HAND in cPanel,
 * so the application must never attempt to create it — and must never call the
 * cPanel UAPI for it — whatever the platform's overall provisioning mode is.
 * Every other tenant keeps the mode's default adapter unchanged, so nothing
 * changes for them.
 */
final class OverrideAwareTenantDatabaseProvisioner implements ProvisionsTenantDatabases
{
    public function __construct(
        private readonly ProvisionsTenantDatabases $default,
        private readonly ProvisionsTenantDatabases $manual,
        private readonly TenantDatabaseName $names,
    ) {}

    public function provision(string $tenantId): void
    {
        $this->adapterFor($tenantId)->provision($tenantId);
    }

    public function drop(string $tenantId): void
    {
        $this->adapterFor($tenantId)->drop($tenantId);
    }

    public function exists(string $tenantId): bool
    {
        return $this->adapterFor($tenantId)->exists($tenantId);
    }

    private function adapterFor(string $tenantId): ProvisionsTenantDatabases
    {
        // Any explicitly named target — the per-partner manual name or the
        // legacy `.env` override — denotes a hand-created database: verify only.
        return $this->names->hasExplicitTarget($tenantId) ? $this->manual : $this->default;
    }
}
