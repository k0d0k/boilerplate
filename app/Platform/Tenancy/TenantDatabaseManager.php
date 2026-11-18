<?php

namespace App\Platform\Tenancy;

use App\Models\Tenant;
use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Platform\Tenancy\Exceptions\TenantDatabaseConflictException;
use App\Platform\Tenancy\Models\TenantDatabaseCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Central-to-tenant connection resolution (Phase 2A).
 *
 * Exactly one tenant database is active at a time. `configure()` re-derives
 * eligibility from the central tenant registry on every switch and always
 * purges the previous connection before re-pointing, so no query can ever be
 * served from a stale connection bound to another tenant's database. `forget()`
 * closes the connection and clears the target.
 *
 * MANUAL database mode (Phase 3): when the platform is configured for manual
 * mode, the per-tenant MySQL credential stored centrally is overlaid onto the
 * connection, so the runtime authenticates as the tenant's own restricted user
 * rather than the shared `.env` credential. A tenant that has no stored
 * credential — every tenant registered before manual mode, and every tenant in
 * cPanel/raw mode — keeps the templated `.env` credential unchanged.
 */
final class TenantDatabaseManager implements ResolvesTenantDatabase
{
    private ?string $current = null;

    /**
     * The PRISTINE tenant connection template, captured before the first switch.
     * `configure()` rewrites the live connection on every switch, so re-reading
     * it would carry a PREVIOUS tenant's manual credential (or target) into the
     * next one. Building from this snapshot keeps each tenant's connection
     * independent.
     *
     * @var array<string, mixed>|null
     */
    private ?array $template = null;

    public function __construct(
        private readonly TenantDatabaseName $names,
        private readonly PlatformSettings $settings,
        private readonly TenantDatabaseConflict $conflicts,
    ) {}

    public function configure(string $tenantId): string
    {
        if (! Str::isUlid($tenantId)) {
            throw TenantAccessDeniedException::forTenant($tenantId);
        }

        // Eligibility is re-derived from central data, never from the caller.
        $tenant = Tenant::query()->whereKey($tenantId)->first();

        if ($tenant === null || ! $tenant->isActive()) {
            throw TenantAccessDeniedException::forTenant($tenantId);
        }

        // A registry conflict can only be introduced by an EXPLICITLY named
        // target — the per-partner manual name recorded in the Principal app, or
        // the legacy single-partner `.env` override. Every other tenant resolves
        // to a name derived from its own ULID, exactly as before — so the check
        // is applied when, and only when, one of those sources names this
        // tenant. It fails closed before the connection is bound to a
        // conflicting database.
        $explicit = $this->names->hasExplicitTarget($tenantId);

        if ($explicit) {
            $conflict = $this->conflicts->for($tenantId);

            if ($conflict !== null) {
                throw TenantDatabaseConflictException::for($tenantId, $conflict);
            }
        }

        $name = $this->connectionName();
        $template = $this->template();

        // The database NAME is always derived from the tenant id — never from
        // user input — so the connection can never be pointed at the central
        // database or another tenant's database.
        $connection = array_merge($template, [
            'database' => $this->names->for($tenantId),
        ]);

        // Drop any existing handle to the previous tenant before re-pointing.
        DB::purge($name);

        config()->set("database.connections.{$name}", $this->withManualCredentials($tenantId, $connection, $explicit));

        DB::purge($name);

        $this->current = $tenantId;

        return $name;
    }

    /**
     * Overlay the tenant's stored MySQL credential when this tenant authenticates
     * as its OWN restricted user: in MANUAL mode, or whenever the tenant's
     * database was explicitly named (a partner configured from the Principal
     * app always carries its own credential). A blank or absent credential
     * leaves the templated `.env` credential in place, so already-registered
     * tenants (and every non-manual, derived-name tenant) are unaffected.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    private function withManualCredentials(string $tenantId, array $connection, bool $explicit): array
    {
        if (! $explicit && $this->settings->databaseMode() !== PlatformSettings::MODE_MANUAL) {
            return $connection;
        }

        $credential = TenantDatabaseCredential::query()->where('tenant_id', $tenantId)->first();

        if (! $credential instanceof TenantDatabaseCredential) {
            return $connection;
        }

        $username = trim((string) $credential->username);

        if ($username !== '') {
            $connection['username'] = $username;
        }

        if (is_string($credential->password) && $credential->password !== '') {
            $connection['password'] = $credential->password;
        }

        return $connection;
    }

    /**
     * The effective connection parameters for a tenant — the connection template
     * with the tenant's database NAME and any per-partner MANUAL credential
     * overlaid — computed WITHOUT binding or purging the live connection.
     *
     * Read-only callers that must authenticate exactly as the runtime would (the
     * pre-deploy `lba:staging:backup`) use this: a partner that owns its own
     * restricted MySQL user is dumped with THAT partner's credential, not the
     * shared `.env` tenant credential. Binding the live connection is
     * deliberately left to {@see configure()}.
     *
     * @return array<string, mixed>
     */
    public function parametersFor(string $tenantId): array
    {
        if (! Str::isUlid($tenantId)) {
            throw TenantAccessDeniedException::forTenant($tenantId);
        }

        $connection = array_merge($this->template(), [
            'database' => $this->names->for($tenantId),
        ]);

        return $this->withManualCredentials($tenantId, $connection, $this->names->hasExplicitTarget($tenantId));
    }

    public function connectionName(): string
    {
        return (string) config('tenancy.tenant_connection');
    }

    public function current(): ?string
    {
        return $this->current;
    }

    public function forget(): void
    {
        $name = $this->connectionName();

        DB::purge($name);

        // Restore the PRISTINE template (clearing any manual credential a
        // previous switch overlaid) so the next switch starts clean.
        if ($this->template !== null) {
            config()->set("database.connections.{$name}", array_merge($this->template, ['database' => null]));
        } else {
            config()->set("database.connections.{$name}.database", null);
        }

        $this->current = null;
    }

    /**
     * The pristine tenant connection template, captured once — before any switch
     * has rewritten the live connection — so manual credentials can never leak
     * from one tenant's connection into another's.
     *
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return $this->template ??= (array) config("database.connections.{$this->connectionName()}", []);
    }
}
