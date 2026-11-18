<?php

namespace App\Platform\Tenancy\Contracts;

/**
 * Runs tenant-scoped migrations against a tenant's isolated database (Phase 2A).
 *
 * Tenant migrations live in their own directory and are applied only to the
 * tenant connection, so central and clinical schemas never mix.
 */
interface RunsTenantMigrations
{
    /**
     * Apply pending tenant migrations to the tenant's database.
     */
    public function migrate(string $tenantId): void;
}
