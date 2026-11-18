<?php

namespace App\Platform\Tenancy\Console;

use App\Platform\Tenancy\Console\Concerns\FindsTenant;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Console\Command;

/**
 * Controlled migration of one tenant's isolated clinical database (Phase 2A).
 *
 * Applies only the tenant migration path to the tenant connection and updates
 * the central registry. Never reachable from a browser request.
 */
class MigrateTenantDatabaseCommand extends Command
{
    use FindsTenant;

    protected $signature = 'lba:tenant:migrate
        {tenant : Tenant ULID or slug}';

    protected $description = 'Run tenant migrations against a tenant database';

    public function handle(RunsTenantMigrations $migrator, TenantDatabaseName $names): int
    {
        $tenant = $this->findTenant($this, (string) $this->argument('tenant'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        $tenantId = (string) $tenant->getKey();

        $migrator->migrate($tenantId);

        // Complete the central registry row: a migration must never leave the
        // `database_name` / `driver` unset, or readiness and the deploy loop
        // would have to re-derive (and could disagree with) the physical name.
        TenantDatabase::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'database_name' => $names->for($tenantId),
                'driver' => $names->driver(),
                'status' => TenantDatabase::STATUS_MIGRATED,
                'last_migrated_at' => now(),
            ],
        );

        $this->info("Tenant [{$tenantId}] migrations applied.");

        return self::SUCCESS;
    }
}
