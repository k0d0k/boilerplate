<?php

namespace App\Platform\Tenancy\Console;

use App\Platform\Tenancy\Console\Concerns\FindsTenant;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Models\TenantDatabase;
use Illuminate\Console\Command;

/**
 * Controlled removal of one tenant's isolated clinical database (Phase 2A).
 *
 * SAFETY PATCH: this is the only destructive tenancy operation, so it is
 * deliberately hard to run by accident:
 *
 *  1. Physical deletion is restricted to the local/testing environments.
 *  2. Staging/production are always denied — `--force` never bypasses this,
 *     because no approved backup, retention or recovery workflow exists yet.
 *  3. In local/testing the operator must explicitly re-type the tenant id to
 *     confirm, so a wrong/ambiguous target cannot be dropped silently.
 *  4. Missing, unknown or unregistered tenant targets fail closed.
 */
class DropTenantDatabaseCommand extends Command
{
    use FindsTenant;

    protected $signature = 'lba:tenant:drop
        {tenant : Tenant ULID or slug}
        {--force : Accepted for convention only; never bypasses the environment guard or identity confirmation}';

    protected $description = 'Drop a tenant database (local/testing only; requires explicit confirmation)';

    public function handle(ProvisionsTenantDatabases $provisioner): int
    {
        // 1 & 2. Environment guard — absolute, not overridable by --force.
        if (! $this->laravel->environment('local', 'testing')) {
            $this->error('Physical tenant database deletion is disabled in the ['
                .$this->laravel->environment().'] environment.');
            $this->error('It is restricted to local/testing until an approved backup, retention and recovery workflow exists.');

            return self::FAILURE;
        }

        if ($this->option('force')) {
            $this->warn('Note: --force does not bypass the tenant identity confirmation.');
        }

        // 4. Fail closed on a missing or unknown tenant target.
        $tenant = $this->findTenant($this, (string) $this->argument('tenant'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        $tenantId = (string) $tenant->getKey();

        // Fail closed when the target was never provisioned/registered.
        if (! TenantDatabase::query()->where('tenant_id', $tenantId)->exists()) {
            $this->error("No provisioned database is registered for tenant [{$tenantId}].");

            return self::FAILURE;
        }

        // 3. Explicit tenant identity confirmation.
        $this->line("About to drop the isolated database for tenant [{$tenantId}].");

        if (! $this->confirmIdentity($tenantId)) {
            $this->error('Tenant identity confirmation did not match. Aborting.');

            return self::FAILURE;
        }

        $provisioner->drop($tenantId);

        TenantDatabase::query()->where('tenant_id', $tenantId)->update([
            'status' => TenantDatabase::STATUS_DROPPED,
        ]);

        $this->info("Dropped tenant [{$tenantId}] database.");

        return self::SUCCESS;
    }

    /**
     * Require the operator to re-type the tenant id. Any mismatch, empty answer
     * or non-interactive run without input aborts.
     */
    private function confirmIdentity(string $tenantId): bool
    {
        $answer = $this->ask('Confirm the tenant id to delete by typing it exactly');

        return is_string($answer) && hash_equals($tenantId, trim($answer));
    }
}
