<?php

namespace App\Platform\Tenancy\Console;

use App\Platform\Tenancy\Console\Concerns\FindsTenant;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Console\Command;

/**
 * Controlled provisioning of one tenant's isolated clinical database (Phase 2A).
 *
 * Runs only from the console with privileged credentials — never from a browser
 * request — and records the outcome in the central `tenant_databases` registry.
 */
class ProvisionTenantDatabaseCommand extends Command
{
    use FindsTenant;

    protected $signature = 'lba:tenant:provision
        {tenant : Tenant ULID or slug}
        {--migrate : Run tenant migrations after provisioning}';

    protected $description = 'Provision the isolated database for a tenant';

    public function handle(
        ProvisionsTenantDatabases $provisioner,
        TenantDatabaseName $names,
        RunsTenantMigrations $migrator,
    ): int {
        $tenant = $this->findTenant($this, (string) $this->argument('tenant'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        $tenantId = (string) $tenant->getKey();
        $databaseName = $names->for($tenantId);

        $provisioner->provision($tenantId);
        $this->info("Provisioned tenant database [{$databaseName}].");

        TenantDatabase::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'database_name' => $databaseName,
                'driver' => $names->driver(),
                'status' => TenantDatabase::STATUS_PROVISIONED,
            ],
        );

        if ($this->option('migrate')) {
            $migrator->migrate($tenantId);

            TenantDatabase::query()->where('tenant_id', $tenantId)->update([
                'status' => TenantDatabase::STATUS_MIGRATED,
                'last_migrated_at' => now(),
            ]);

            $this->info('Tenant migrations applied.');
        }

        return self::SUCCESS;
    }
}
