<?php

namespace App\Platform\Tenancy\Cpanel;

use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Exceptions\TenantDatabaseDeletionDeniedException;
use App\Platform\Tenancy\TenantDatabaseName;
use RuntimeException;

/**
 * cPanel UAPI tenant database provisioner (Phase 3 — one-click clinic setup).
 *
 * Replaces the raw `CREATE DATABASE` path for cPanel-hosted installations. The
 * Laravel runtime credential is NEVER given global CREATE or USER privileges:
 * cPanel (the hosting account) creates the database and then grants the
 * restricted runtime user privileges ON THAT ONE DATABASE. Provisioning and
 * runtime privileges stay strictly separate:
 *
 *   - provisioning  → the cPanel account + API token (server-side, from env)
 *   - runtime       → TENANT_DB_USERNAME, granted per-database only
 *
 * Provisioning is idempotent and safe to retry: an existing database is
 * DETECTED via `Mysql::list_databases` and reused — never re-created, never
 * dropped — so a retried "Setup Clinic" can neither duplicate a database nor
 * lose data. Newly created databases are simply re-granted on a retry.
 */
final class CpanelTenantDatabaseProvisioner implements ProvisionsTenantDatabases
{
    public function __construct(
        private readonly TenantDatabaseName $names,
        private readonly CpanelClient $client,
    ) {}

    public function provision(string $tenantId): void
    {
        $physical = $this->names->for($tenantId);

        // Detect an existing database and reuse it safely: only grant
        // privileges, never re-create or drop.
        if ($this->client->databaseExists($physical)) {
            $this->grantRuntimePrivileges($tenantId);

            return;
        }

        // cPanel owns the privileged DDL; the runtime credential never runs it.
        $this->client->createDatabase($this->names->requestedName($tenantId));
        $this->grantRuntimePrivileges($tenantId);
    }

    public function drop(string $tenantId): void
    {
        // Same absolute safety boundary as the raw provisioner: physical
        // deletion is local/testing only, with no override.
        if (! app()->environment('local', 'testing')) {
            throw TenantDatabaseDeletionDeniedException::environment((string) app()->environment());
        }

        // Only delete when it actually exists, so an idempotent retry is safe.
        if ($this->client->databaseExists($this->names->for($tenantId))) {
            $this->client->deleteDatabase($this->names->requestedName($tenantId));
        }
    }

    public function exists(string $tenantId): bool
    {
        return $this->client->databaseExists($this->names->for($tenantId));
    }

    /**
     * Grant the restricted runtime user privileges on the tenant database only.
     *
     * cPanel requires BOTH the `database` and `user` arguments of
     * `Mysql::set_privileges_on_database` to be ACCOUNT-PREFIXED
     * (`<account>_<name>`) when database prefixing is enabled (the cPanel
     * default). The unprefixed name is only what cPanel expects on
     * `create_database`; reusing it here would target the wrong (non-existent)
     * user/database and fail on a real server.
     */
    private function grantRuntimePrivileges(string $tenantId): void
    {
        $user = $this->names->prefixedRuntimeUser();

        if ($user === null) {
            throw new RuntimeException(
                'cPanel provisioning requires a runtime database user '
                .'(set CPANEL_RUNTIME_DB_USER) so privileges are granted per-database only.',
            );
        }

        // Prefixed database (what cPanel actually created) + prefixed user.
        $this->client->setPrivilegesOnDatabase(
            $this->names->for($tenantId),
            $user,
        );
    }
}
