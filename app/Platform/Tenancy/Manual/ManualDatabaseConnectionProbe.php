<?php

namespace App\Platform\Tenancy\Manual;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\TenantDatabaseConflict;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * READ-ONLY manual-mode connection probe (Phase 3 — "Uji Koneksi").
 *
 * Answers, WITHOUT changing anything, whether the application can reach the
 * tenant's manually-created database with the stored credential and whether the
 * expected schema is present. It makes NO cPanel UAPI call, runs NO DDL and
 * NEVER migrates — the only statement issued against the tenant connection is a
 * liveness `select 1`.
 *
 * The projection carries no credential: a failure is reduced to a safe reason
 * code so no password, host or raw driver error can leak into a response, a
 * view or a log. The database NAME it reports is the name already shown on the
 * screen, resolved through the SAME {@see TenantDatabaseName} the runtime
 * connection and the migrator use.
 *
 * A name that conflicts with the central registry (the central database, a
 * database registered for another tenant, or a different name than the one
 * already recorded) is REPORTED as the probe's reason, so the operator can
 * inspect the database and the registry before continuing.
 */
final class ManualDatabaseConnectionProbe
{
    /** Safe reason code for an override that is not a usable database name. */
    public const REASON_INVALID_CONFIGURATION = 'invalid_configuration';

    public function __construct(
        private readonly TenantDatabaseName $names,
        private readonly ResolvesTenantDatabase $databases,
        private readonly TenantDatabaseConflict $conflicts,
    ) {}

    /**
     * @return array{configured: bool, connected: bool, database_present: bool, driver: string, target: string, reason: ?string}
     */
    public function run(string $tenantId): array
    {
        $driver = $this->names->driver();

        $result = [
            'configured' => true,
            'connected' => false,
            'database_present' => false,
            'driver' => $driver,
            'target' => '',
            'reason' => null,
        ];

        try {
            $target = $this->names->for($tenantId);
            $conflict = $this->conflicts->for($tenantId);
        } catch (Throwable) {
            // The override is not a usable name (bad identifier, or it names the
            // central database): report a safe code, never the raw message.
            $result['reason'] = self::REASON_INVALID_CONFIGURATION;

            return $result;
        }

        $result['target'] = $target;

        if ($conflict !== null) {
            // Report the conflict WITHOUT touching the database.
            $result['reason'] = $conflict;

            return $result;
        }

        try {
            $connection = $this->databases->configure($tenantId);

            // Read-only liveness: a plain `select 1` never mutates anything.
            DB::connection($connection)->select('select 1');

            $result['connected'] = true;
            // For MySQL/MariaDB a successful connect with the target bound as
            // the default schema proves the database exists; for SQLite it is
            // the file on disk.
            $result['database_present'] = $driver === 'sqlite' ? File::exists($target) : true;

            if (! $result['database_present']) {
                $result['reason'] = 'database_missing';
            }
        } catch (Throwable) {
            // Surface only a safe code — never the raw driver text.
            $result['reason'] = 'connection_failed';
        }

        return $result;
    }
}
