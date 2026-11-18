<?php

namespace App\Platform\Tenancy;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use Illuminate\Support\Facades\Artisan;

/**
 * Applies tenant migrations to a tenant's isolated database (Phase 2A).
 *
 * Delegates to the framework migrator with an explicit `--database`, so only
 * the tenant connection and the tenant migration path are touched and the
 * central schema is never affected.
 */
final class TenantMigrator implements RunsTenantMigrations
{
    public function __construct(private readonly ResolvesTenantDatabase $databases) {}

    public function migrate(string $tenantId): void
    {
        $connection = $this->databases->configure($tenantId);

        Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => (string) config('tenancy.migration_path'),
            '--realpath' => true,
            '--force' => true,
        ]);
    }
}
