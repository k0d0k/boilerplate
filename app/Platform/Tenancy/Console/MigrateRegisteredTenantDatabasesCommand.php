<?php

namespace App\Platform\Tenancy\Console;

use App\Models\Tenant;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run tenant migrations for EVERY registered tenant database.
 *
 * The staging deployment step that keeps already-provisioned clinics current
 * after the central schema is migrated. It is deliberately conservative:
 *
 *  - it only walks CENTRAL registry rows that are already provisioned or
 *    migrated — never a pending, failed or dropped database, and never a
 *    tenant that was not provisioned through the controlled path;
 *  - it is idempotent (migrations are applied by the framework migrator);
 *  - it NEVER drops, re-creates or overwrites a tenant database;
 *  - a failure on one tenant is reported and does not stop the others, but the
 *    command exits non-zero so the deploy can halt safely.
 *
 * Console-only, privileged: never reachable from a browser request.
 */
class MigrateRegisteredTenantDatabasesCommand extends Command
{
    protected $signature = 'lba:tenant:migrate:registered';

    protected $description = 'Run tenant migrations for every registered (provisioned/migrated) tenant database';

    public function handle(RunsTenantMigrations $migrator, TenantDatabaseName $names): int
    {
        $rows = TenantDatabase::query()
            ->whereIn('status', [
                TenantDatabase::STATUS_PROVISIONED,
                TenantDatabase::STATUS_MIGRATED,
            ])
            ->orderBy('tenant_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No registered tenant databases to migrate.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($rows as $row) {
            $tenantId = (string) $row->tenant_id;

            if (! Tenant::query()->whereKey($tenantId)->exists()) {
                $this->warn("Skipping [{$tenantId}]: no matching central tenant.");

                continue;
            }

            try {
                $migrator->migrate($tenantId);

                $row->forceFill([
                    'database_name' => $names->for($tenantId),
                    'driver' => $names->driver(),
                    'status' => TenantDatabase::STATUS_MIGRATED,
                    'last_migrated_at' => now(),
                ])->save();

                $this->info("Migrated tenant [{$tenantId}].");
            } catch (Throwable $e) {
                $failures++;
                $this->error("Tenant [{$tenantId}] migration failed: {$e->getMessage()}");
            }
        }

        if ($failures > 0) {
            $this->error("{$failures} tenant database(s) failed to migrate.");

            return self::FAILURE;
        }

        $this->info('All registered tenant databases are up to date.');

        return self::SUCCESS;
    }
}
