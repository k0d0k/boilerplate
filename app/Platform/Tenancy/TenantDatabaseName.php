<?php

namespace App\Platform\Tenancy;

use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Platform\Tenancy\Models\TenantDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Derives the physical tenant database target from an opaque tenant id (Phase 2A).
 *
 * The target is always computed from the tenant's ULID — never from user input —
 * so a caller can never point the connection at an arbitrary schema or file.
 *
 * cPanel compatibility (Phase 3): on a cPanel host the server prepends the
 * account username to every database it creates, so the tenant database's
 * PHYSICAL name is `<account>_<prefix><ulid>`. {@see for()} returns that
 * physical name (what the runtime connection must use), while
 * {@see requestedName()} returns the unprefixed name cPanel expects. The name
 * is validated against the MySQL / cPanel identifier rules (≤ 64 characters,
 * `[A-Za-z0-9_]`), so an over-long or unsafe name fails closed before any DDL.
 *
 * Staging override: ONE tenant may be pointed at an explicitly named physical
 * database (created by hand in cPanel) through the optional `TENANT_DB` +
 * `TENANT_DB_TENANT_ID` configuration — see {@see manualName()}. This is inert
 * unless both values are set, and only the tenant whose ULID matches exactly is
 * affected; every other tenant keeps the derived name above.
 *
 * Per-partner manual name (v3.3): an operator may instead record a physical
 * database name for ANY partner from the Principal app
 * (`tenant_databases.configured_database_name`, see {@see configuredName()}).
 * That source is consulted FIRST and needs no `.env` entry, so two partners can
 * share a brand while owning differently named databases. A partner without a
 * configured name — every legacy partner — falls through to the two sources
 * above unchanged.
 */
final class TenantDatabaseName
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * The physical database name for the tenant: what the runtime connection
     * binds to. Usually the derived `<account>_<prefix><ulid>` (or a SQLite
     * file); for a partner with an operator-chosen name it is that name, and for
     * the single staging tenant named in `TENANT_DB_TENANT_ID` it is the
     * explicitly configured `TENANT_DB` name instead.
     */
    public function for(string $tenantId): string
    {
        $this->assertUlid($tenantId);

        // 1. The name an operator recorded for THIS partner in the Principal app.
        $configured = $this->configuredTarget($tenantId);

        if ($configured !== null) {
            return $configured;
        }

        // 2. Optional single-partner staging override — inert unless both
        // TENANT_DB and TENANT_DB_TENANT_ID are set, and only for the
        // designated partner.
        $manual = $this->manualName($tenantId);

        if ($manual !== null) {
            return $manual;
        }

        // 3. Derived from the partner id.
        if ($this->driver() === 'sqlite') {
            return $this->sqlitePath($tenantId);
        }

        $account = $this->account();
        $physical = $account === null
            ? $this->requestedName($tenantId)
            : $account.'_'.$this->requestedName($tenantId);

        $this->assertIdentifier($physical);

        return $physical;
    }

    /**
     * The physical TARGET for a partner whose database name was recorded in the
     * Principal app, or null when the partner never used the manual form.
     *
     * For MySQL/MariaDB this is the name exactly as the operator created it in
     * cPanel (which already carries the hosting account prefix). For SQLite —
     * the sandbox/test engine — the name is mapped into the configured SQLite
     * directory so it still denotes one distinct database per name.
     */
    private function configuredTarget(string $tenantId): ?string
    {
        $name = $this->configuredName($tenantId);

        return $name === null ? null : $this->targetForConfiguredName($name);
    }

    /**
     * Resolve an operator-entered physical database name into the target the
     * runtime connection binds to, validating it as a MySQL/cPanel identifier
     * and refusing the central platform database.
     *
     * Exposed so the Principal write path can record the SAME target the
     * resolver will derive, without a database round-trip.
     */
    public function targetForConfiguredName(string $name): string
    {
        $name = trim($name);

        $this->assertIdentifier($name, 'the partner database name');

        if ($name === $this->centralDatabaseName()) {
            throw new RuntimeException(
                "The partner database name [{$name}] must not name the central platform database.",
            );
        }

        return $this->driver() === 'sqlite'
            ? rtrim((string) config('tenancy.sqlite_path'), '/').'/'.$this->sanitize($name).'.sqlite'
            : $name;
    }

    /**
     * The operator-entered physical database name for this partner, or null when
     * none was recorded (or the central registry is not yet migrated).
     *
     * The value is validated as a MySQL/cPanel identifier and may never name the
     * central platform database, so a bad or hostile submission fails closed
     * before any connection is bound or any DDL is run.
     */
    public function configuredName(string $tenantId): ?string
    {
        try {
            $name = TenantDatabase::query()
                ->where('tenant_id', $tenantId)
                ->value('configured_database_name');
        } catch (Throwable) {
            // The central registry table does not exist yet (fresh install,
            // migration): behave exactly as "no configured name".
            return null;
        }

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $name = trim($name);

        $this->assertIdentifier($name, 'the partner database name');

        if ($name === $this->centralDatabaseName()) {
            throw new RuntimeException(
                "The partner database name [{$name}] must not name the central platform database.",
            );
        }

        return $name;
    }

    /**
     * Whether this partner resolves through an EXPLICITLY named target — the
     * operator-recorded manual name, or the legacy single-partner `.env`
     * override. Only such partners can introduce a registry conflict, so only
     * they pay for the conflict check before a connection is bound.
     */
    public function hasExplicitTarget(string $tenantId): bool
    {
        return $this->configuredName($tenantId) !== null
            || $this->manualName($tenantId) !== null;
    }

    /**
     * The explicitly configured PHYSICAL database name for the ONE tenant named
     * by `TENANT_DB_TENANT_ID`, or null when the override is not in play.
     *
     * The override is active ONLY when BOTH `TENANT_DB` and `TENANT_DB_TENANT_ID`
     * are supplied AND the id being accessed equals `TENANT_DB_TENANT_ID` exactly.
     * In that case the configured name is validated as a MySQL identifier and
     * returned as-is; every other tenant — and every installation that leaves
     * the values unset — keeps the existing derived resolution untouched.
     *
     * A designated id that is not a valid ULID can never match a real tenant, so
     * it fails loudly instead of silently disabling the override. The manual
     * name may never be the central platform database.
     */
    public function manualName(string $tenantId): ?string
    {
        $database = trim((string) config('tenancy.manual_database.database', ''));
        $designated = trim((string) config('tenancy.manual_database.tenant_id', ''));

        // Inert unless BOTH values are supplied.
        if ($database === '' || $designated === '') {
            return null;
        }

        if (! Str::isUlid($designated)) {
            throw new RuntimeException(
                'TENANT_DB_TENANT_ID must be a valid ULID; got ['.$designated.'].',
            );
        }

        // Only the ONE tenant named in the environment uses the manual name.
        if (! hash_equals($designated, $tenantId)) {
            return null;
        }

        $this->assertIdentifier($database, 'TENANT_DB');

        // Hard boundary: a tenant must never be bound to the central platform
        // database, whatever the environment says.
        if ($database === $this->centralDatabaseName()) {
            throw new RuntimeException(
                "TENANT_DB [{$database}] must not name the central platform database.",
            );
        }

        return $database;
    }

    /**
     * The central platform database name, read from the central connection, or
     * null when it is not a concrete name.
     */
    public function centralDatabaseName(): ?string
    {
        $central = (string) config('tenancy.central_connection');
        $name = config("database.connections.{$central}.database");

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The UNPREFIXED database name requested from cPanel (cPanel prepends the
     * account username server-side). Equals the legacy physical name when
     * cPanel provisioning is disabled.
     */
    public function requestedName(string $tenantId): string
    {
        $this->assertUlid($tenantId);

        return (string) config('tenancy.database_prefix').$this->sanitize($tenantId);
    }

    /**
     * The hosting account whose username prefixes the physical database names,
     * or null when names are NOT prefixed. Read from the EFFECTIVE configuration
     * (persisted Global Settings overlaid on `.env`) so a change made in the
     * settings screen is picked up by every context (web, Artisan, queue).
     *
     * Active while cPanel provisioning is enabled OR while manual mode is on:
     * a database created by hand in cPanel still carries the account prefix, so
     * the derived name must match it. A legacy installation (cPanel disabled,
     * mode `cpanel`) keeps its unprefixed names.
     */
    public function account(): ?string
    {
        if (! $this->settings->databaseNamesPrefixed()) {
            return null;
        }

        $account = trim((string) ($this->settings->effectiveCpanel()['username'] ?? ''));

        return $account === '' ? null : $account;
    }

    /**
     * The restricted runtime database user cPanel grants per-database
     * privileges to, or null when not configured. Unprefixed — this is the
     * credential the runtime Laravel connection authenticates as.
     */
    public function runtimeUser(): ?string
    {
        $user = trim((string) ($this->settings->effectiveCpanel()['runtime_user'] ?? ''));

        return $user === '' ? null : $user;
    }

    /**
     * The ACCOUNT-PREFIXED runtime user name cPanel needs for its
     * `set_privileges_on_database` / `get_privileges_on_database` calls.
     *
     * cPanel requires BOTH the database and the user arguments prefixed with
     * `<account>_` when database prefixing is enabled (the cPanel default).
     * The unprefixed {@see runtimeUser()} is what the runtime connection uses;
     * this is the server-side name cPanel grants to. Falls back to the
     * unprefixed value when no account is configured.
     */
    public function prefixedRuntimeUser(): ?string
    {
        $user = $this->runtimeUser();

        if ($user === null) {
            return null;
        }

        $account = $this->account();

        return $account === null ? $user : $account.'_'.$user;
    }

    public function driver(): string
    {
        return (string) config(
            'database.connections.'.config('tenancy.tenant_connection').'.driver',
            'sqlite',
        );
    }

    private function sqlitePath(string $tenantId): string
    {
        return rtrim((string) config('tenancy.sqlite_path'), '/').'/'.$this->sanitize($tenantId).'.sqlite';
    }

    private function sanitize(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '_', $value));
    }

    private function assertUlid(string $tenantId): void
    {
        if (! Str::isUlid($tenantId)) {
            throw TenantAccessDeniedException::forTenant($tenantId);
        }
    }

    /**
     * Whether a value is a usable MySQL/cPanel database identifier (1–64
     * characters, letters, digits and underscore only).
     */
    public function isValidIdentifier(string $name): bool
    {
        $name = trim($name);

        return $name !== ''
            && strlen($name) <= 64
            && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }

    /**
     * Fail closed when a derived MySQL/cPanel database name would be rejected by
     * the server (max 64 chars, `[A-Za-z0-9_]`).
     */
    private function assertIdentifier(string $name, string $hint = 'TENANT_DB_PREFIX and CPANEL_USERNAME'): void
    {
        if (! $this->isValidIdentifier($name)) {
            throw new RuntimeException(
                "Derived tenant database name [{$name}] is not a valid MySQL/cPanel identifier "
                ."(1–64 characters, letters, digits and underscore only). Check {$hint}.",
            );
        }
    }
}
