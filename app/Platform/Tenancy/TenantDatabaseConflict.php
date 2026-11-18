<?php

namespace App\Platform\Tenancy;

use App\Platform\Tenancy\Models\TenantDatabase;

/**
 * Reports the conflict, if any, between the physical database a tenant resolves
 * to RIGHT NOW and the central `tenant_databases` registry.
 *
 * The name comes from the SAME {@see TenantDatabaseName::for()} the runtime
 * connection, "Uji Koneksi", the migrator and the registry writers use, so every
 * consumer observes a conflict identically. A caller refuses to continue
 * whenever a reason is returned, so no database is ever created, migrated or
 * bound to a name that is:
 *
 *   - `central_database`             — the platform's own central database;
 *   - `registered_to_another_tenant` — already registered for a DIFFERENT tenant;
 *   - `recorded_database_mismatch`   — different from the name already recorded
 *                                      for THIS tenant (re-pointing a live
 *                                      database away from its data).
 *
 * Returning a reason (rather than throwing) lets each surface REPORT the
 * conflict — so the operator can inspect the database and the registry before
 * continuing — while still failing closed.
 */
final class TenantDatabaseConflict
{
    public const CENTRAL_DATABASE = 'central_database';

    public const REGISTERED_TO_ANOTHER_TENANT = 'registered_to_another_tenant';

    public const RECORDED_DATABASE_MISMATCH = 'recorded_database_mismatch';

    public function __construct(private readonly TenantDatabaseName $names) {}

    /**
     * One of the conflict reason codes, or null when the resolved name is
     * consistent with the central registry.
     */
    public function for(string $tenantId): ?string
    {
        $target = $this->names->for($tenantId);

        $central = $this->names->centralDatabaseName();

        if ($central !== null && $target === $central) {
            return self::CENTRAL_DATABASE;
        }

        $claimedByAnother = TenantDatabase::query()
            ->where('database_name', $target)
            ->where('tenant_id', '!=', $tenantId)
            ->exists();

        if ($claimedByAnother) {
            return self::REGISTERED_TO_ANOTHER_TENANT;
        }

        $recorded = (string) (TenantDatabase::query()
            ->where('tenant_id', $tenantId)
            ->value('database_name') ?? '');

        if ($recorded !== '' && $recorded !== $target) {
            return self::RECORDED_DATABASE_MISMATCH;
        }

        return null;
    }
}
