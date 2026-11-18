<?php

namespace App\Platform\Tenancy\Manual;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\Models\TenantDatabaseCredential;
use App\Platform\Tenancy\TenantDatabaseConflict;
use App\Platform\Tenancy\TenantDatabaseName;
use Throwable;

/**
 * The single write path for a partner's MANUAL database configuration (v3.3).
 *
 * "Tambah Mitra" records the physical MySQL name an operator created by hand in
 * cPanel (e.g. `lbacloud_tenant`) plus the restricted MySQL credential. Nothing
 * is created, dropped, migrated or even contacted here: the registry row is
 * saved as `pending` (Menunggu) and the connection is only PROVEN later by
 * "Uji Koneksi" and only MIGRATED by "Setup Klinik".
 *
 * Two guarantees the Principal UI must never be trusted for, so they live here:
 *
 *   1. NO AMBIGUOUS TARGET — {@see reason()} refuses
 *      - the central platform database (`central_database`);
 *      - a name already recorded for ANOTHER partner
 *        (`registered_to_another_tenant`);
 *      - a different name for a partner whose database is already
 *        provisioned/migrated (`recorded_database_mismatch`), so a live
 *        database is never silently re-pointed away from its data.
 *   2. NO `.env` PER PARTNER — the name and credential live in central
 *      non-clinical tables; host/port still come from the server configuration.
 */
final class ManualTenantDatabaseConfigurator
{
    /** The submitted name is not a usable MySQL/cPanel identifier. */
    public const INVALID_NAME = 'invalid_name';

    public function __construct(
        private readonly TenantDatabaseName $names,
    ) {}

    /**
     * The reason the submitted name may NOT be used, or null when it is safe.
     *
     * Returns a stable reason code (never a raw driver/exception message) so the
     * caller can localise it. A partner argument is required to protect an
     * already-provisioned partner from being re-pointed; pass null when
     * validating a brand-new partner.
     */
    public function reason(string $name, ?Tenant $tenant = null): ?string
    {
        $name = trim($name);

        if (! $this->names->isValidIdentifier($name)) {
            return self::INVALID_NAME;
        }

        if ($name === $this->names->centralDatabaseName()) {
            return TenantDatabaseConflict::CENTRAL_DATABASE;
        }

        try {
            $target = $this->names->targetForConfiguredName($name);
        } catch (Throwable) {
            return self::INVALID_NAME;
        }

        $claimedByAnother = TenantDatabase::query()
            ->when(
                $tenant !== null,
                fn ($query) => $query->where('tenant_id', '!=', $tenant->getKey()),
            )
            ->where(function ($query) use ($target, $name): void {
                $query->where('database_name', $target)
                    ->orWhere('configured_database_name', $name);
            })
            ->exists();

        if ($claimedByAnother) {
            return TenantDatabaseConflict::REGISTERED_TO_ANOTHER_TENANT;
        }

        if ($tenant === null) {
            return null;
        }

        $recorded = TenantDatabase::query()->where('tenant_id', $tenant->getKey())->first();

        // A partner whose database has already been provisioned or migrated keeps
        // the name it was provisioned with: only an empty/pending/failed setup
        // may still be corrected.
        if ($recorded instanceof TenantDatabase
            && in_array($recorded->status, [
                TenantDatabase::STATUS_PROVISIONED,
                TenantDatabase::STATUS_MIGRATED,
            ], true)
            && trim((string) $recorded->configured_database_name) !== $name
        ) {
            return TenantDatabaseConflict::RECORDED_DATABASE_MISMATCH;
        }

        return null;
    }

    /**
     * Record the partner's manual database target and credential.
     *
     * The registry row is written as `pending` (a migrated partner keeps its
     * `migrated` state), the resolved physical target is stored verbatim so the
     * conflict guard compares like with like, and a blank password keeps the
     * stored (encrypted) one.
     */
    public function configure(
        Tenant $tenant,
        string $name,
        ?string $username = null,
        ?string $password = null,
    ): TenantDatabase {
        $tenantId = (string) $tenant->getKey();
        $name = trim($name);
        $target = $this->names->targetForConfiguredName($name);
        $driver = $this->names->driver();

        return $tenant->getConnection()->transaction(function () use (
            $tenantId,
            $name,
            $target,
            $driver,
            $username,
            $password,
        ): TenantDatabase {
            $record = TenantDatabase::query()->firstOrNew(['tenant_id' => $tenantId]);
            $record->configured_database_name = $name;
            $record->database_name = $target;
            $record->driver = $driver;

            if ($record->status !== TenantDatabase::STATUS_MIGRATED) {
                $record->status = TenantDatabase::STATUS_PENDING;
            }

            $record->save();

            $password = (string) $password;

            if ($username !== null || $password !== '') {
                $credential = TenantDatabaseCredential::query()->firstOrNew(['tenant_id' => $tenantId]);

                if ($username !== null) {
                    $credential->username = trim($username);
                }

                if ($password !== '') {
                    $credential->password = $password;
                }

                $credential->save();
            }

            return $record;
        }, 3);
    }
}
