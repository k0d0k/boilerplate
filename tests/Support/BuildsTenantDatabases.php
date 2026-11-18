<?php

namespace Tests\Support;

use App\Models\Tenant;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Builds isolated temporary SQLite tenant databases for Phase 2A tests.
 *
 * MySQL/MariaDB is unavailable in the sandbox, so each tenant gets its own
 * temporary SQLite file — a genuine separate database, which proves isolation
 * without a running MySQL server. Real database-engine validation is pending.
 */
trait BuildsTenantDatabases
{
    protected string $tenantDatabasePath = '';

    protected function bootTenantDatabases(): void
    {
        $this->tenantDatabasePath = storage_path('framework/testing/tenants/'.strtolower((string) Str::ulid()));

        File::ensureDirectoryExists($this->tenantDatabasePath);

        config()->set('tenancy.sqlite_path', $this->tenantDatabasePath);
        config()->set('database.connections.tenant.driver', 'sqlite');
        config()->set('database.connections.tenant.database', null);

        $this->tenantDatabases()->forget();
    }

    protected function teardownTenantDatabases(): void
    {
        if ($this->tenantDatabasePath !== '') {
            $this->tenantDatabases()->forget();

            if (File::isDirectory($this->tenantDatabasePath)) {
                File::deleteDirectory($this->tenantDatabasePath);
            }
        }
    }

    protected function tenantDatabases(): ResolvesTenantDatabase
    {
        return app(ResolvesTenantDatabase::class);
    }

    /**
     * Provision and migrate an isolated database for the tenant, then release
     * the connection so the test starts from a clean lifecycle state.
     */
    protected function provisionTenantDatabase(Tenant $tenant): void
    {
        $tenantId = (string) $tenant->getKey();

        app(ProvisionsTenantDatabases::class)->provision($tenantId);
        app(RunsTenantMigrations::class)->migrate($tenantId);

        $this->tenantDatabases()->forget();
    }

    protected function tenantDatabaseFile(Tenant $tenant): string
    {
        return app(TenantDatabaseName::class)->for((string) $tenant->getKey());
    }
}
