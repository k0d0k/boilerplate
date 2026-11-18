<?php

namespace App\Platform\Tenancy;

use App\Platform\Tenancy\Models\TenantDatabase;

/**
 * Central read model for a tenant's isolated clinical database readiness
 * (WS4 Priority 0).
 *
 * The Clinic panel must never bind a tenant database whose migrations have not
 * been applied: an empty schema (pending / provisioned) or a failed / dropped
 * database would surface as an opaque SQL error mid-request. This service
 * answers, from the CENTRAL `tenant_databases` registry only, whether the
 * tenant's database is usable, so the Clinic gateway can fail closed with a
 * useful message instead.
 *
 * Design boundary (FAIL CLOSED):
 *  - Only a registry row explicitly marked `migrated` is READY. Anything else
 *    — pending, provisioned-but-not-migrated, failed or dropped — is denied,
 *    because the schema and its tables are not guaranteed.
 *  - The ABSENCE of a registry row is also NOT ready. A tenant database can
 *    only be trusted when its provisioning state has been recorded centrally
 *    and verifiably reached `migrated`; an unregistered tenant is treated as
 *    unverified, never as implicitly usable. Every legitimate tenant is
 *    registered when it is provisioned (see the provisioning command), so a
 *    missing row always means "not verified" — never "legacy, assume ready".
 */
final class TenantDatabaseReadiness
{
    public function isReady(string $tenantId): bool
    {
        $database = TenantDatabase::query()
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $database instanceof TenantDatabase) {
            return false;
        }

        return $database->status === TenantDatabase::STATUS_MIGRATED;
    }
}
