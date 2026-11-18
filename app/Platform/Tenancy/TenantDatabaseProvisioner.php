<?php

namespace App\Platform\Tenancy;

use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Exceptions\TenantDatabaseDeletionDeniedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Driver-aware tenant database provisioning (Phase 2A).
 *
 * SQLite uses one file per tenant (used for isolation testing where no MySQL
 * server is available). MySQL/MariaDB use one schema per tenant, created with
 * the privileged provisioning connection.
 */
final class TenantDatabaseProvisioner implements ProvisionsTenantDatabases
{
    public function __construct(private readonly TenantDatabaseName $names) {}

    public function provision(string $tenantId): void
    {
        $target = $this->names->for($tenantId);

        match ($this->names->driver()) {
            'sqlite' => $this->provisionSqlite($target),
            'mysql', 'mariadb' => $this->statement(
                "CREATE DATABASE IF NOT EXISTS `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            ),
            default => throw new RuntimeException(
                'Tenant provisioning is not supported for driver ['.$this->names->driver().'].',
            ),
        };
    }

    public function drop(string $tenantId): void
    {
        // Hard safety boundary: physical deletion is only permitted in
        // local/testing. There is no override, so no caller can force a drop in
        // staging or production.
        if (! app()->environment('local', 'testing')) {
            throw TenantDatabaseDeletionDeniedException::environment((string) app()->environment());
        }

        $target = $this->names->for($tenantId);

        match ($this->names->driver()) {
            'sqlite' => File::delete($target),
            'mysql', 'mariadb' => $this->statement("DROP DATABASE IF EXISTS `{$target}`"),
            default => throw new RuntimeException(
                'Tenant provisioning is not supported for driver ['.$this->names->driver().'].',
            ),
        };
    }

    public function exists(string $tenantId): bool
    {
        $target = $this->names->for($tenantId);

        if ($this->names->driver() === 'sqlite') {
            return File::exists($target);
        }

        $row = DB::connection($this->connection())
            ->selectOne('select schema_name from information_schema.schemata where schema_name = ?', [$target]);

        return $row !== null;
    }

    private function provisionSqlite(string $path): void
    {
        File::ensureDirectoryExists(dirname($path));

        if (! File::exists($path)) {
            File::put($path, '');
        }
    }

    private function statement(string $sql): void
    {
        DB::connection($this->connection())->statement($sql);
    }

    private function connection(): string
    {
        return (string) config('tenancy.provisioning_connection');
    }
}
