<?php

namespace App\Platform\Tenancy\Manual;

use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * MANUAL database-mode provisioner (Phase 3).
 *
 * In manual mode the tenant database is created BY HAND in cPanel and the
 * application NEVER calls the cPanel UAPI. This adapter therefore runs no DDL
 * and needs no privileged credential:
 *
 *   - `provision()` only VERIFIES that the expected database is reachable, so a
 *     typo in the manually-created name fails closed instead of silently
 *     migrating a missing or wrong database. It never CREATEs and never drops.
 *   - `exists()` is a READ-ONLY reachability check against the target the
 *     application derives from the tenant id.
 *   - `drop()` always refuses: a database created and owned manually is never
 *     removed by the application.
 *
 * The target name still comes from {@see TenantDatabaseName::for()}, so it can
 * only ever be the tenant's own database — never the central database and never
 * another tenant's database.
 */
final class ManualTenantDatabaseProvisioner implements ProvisionsTenantDatabases
{
    public function __construct(
        private readonly TenantDatabaseName $names,
        private readonly ResolvesTenantDatabase $databases,
    ) {}

    public function provision(string $tenantId): void
    {
        if ($this->exists($tenantId)) {
            return;
        }

        throw new RuntimeException(
            'Manual tenant database ['.$this->names->for($tenantId).'] is not reachable. '
            .'Create it with exactly that name in cPanel and store the MySQL credentials, then retry.',
        );
    }

    public function drop(string $tenantId): void
    {
        throw new RuntimeException(
            'A manually-managed tenant database is never dropped by the application.',
        );
    }

    public function exists(string $tenantId): bool
    {
        $target = $this->names->for($tenantId);

        if ($this->names->driver() === 'sqlite') {
            return File::exists($target);
        }

        try {
            // Connecting with the target as the default schema succeeds only
            // when the database exists AND the tenant credential may use it;
            // the query itself is read-only.
            $connection = $this->databases->configure($tenantId);

            return DB::connection($connection)->selectOne(
                'select schema_name from information_schema.schemata where schema_name = ?',
                [$target],
            ) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
