<?php

/**
 * Multi-tenant database configuration (Phase 2A).
 *
 * One central database holds the platform registry (identity, brands, tenants,
 * memberships, modules). Each independent tenant has its OWN isolated clinical
 * database; every branch of a tenant shares that tenant database (ARCH-04).
 *
 * No credentials live in source code: every value below is supplied through the
 * environment. Runtime connections use a restricted tenant credential that is
 * separate from the privileged credential used only for provisioning.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Connection names
    |--------------------------------------------------------------------------
    |
    | `central_connection` is the application's default connection (the central
    | platform database). `tenant_connection` is the templated connection that
    | is re-pointed at an isolated tenant database at runtime.
    |
    */
    'central_connection' => env('TENANCY_CENTRAL_CONNECTION', env('DB_CONNECTION', 'sqlite')),

    'tenant_connection' => env('TENANCY_TENANT_CONNECTION', 'tenant'),

    /*
    |--------------------------------------------------------------------------
    | Tenant database naming
    |--------------------------------------------------------------------------
    |
    | Each tenant database is derived from the tenant's opaque ULID, never from
    | user input. For MySQL/MariaDB the physical database name is the prefix
    | plus the sanitized tenant id; for SQLite it is a per-tenant file.
    |
    */
    'database_prefix' => env('TENANT_DB_PREFIX', 'lba_tenant_'),

    'sqlite_path' => env('TENANT_DB_SQLITE_PATH', storage_path('app/tenants')),

    /*
    |--------------------------------------------------------------------------
    | Staging manual tenant database (single tenant, `.env` override)
    |--------------------------------------------------------------------------
    |
    | On staging a tenant database may be created BY HAND in cPanel and given an
    | explicit PHYSICAL name (e.g. `lbacloud_tenant`), independent of the derived
    | `<prefix><ulid>`. This override is OFF unless BOTH values below are set:
    |
    |   - `database`  — the exact physical database name to use;
    |   - `tenant_id` — the ULID of the ONE tenant allowed to use it.
    |
    | When both are set, ONLY the tenant whose ULID equals `tenant_id` resolves
    | to `database`; every other tenant keeps the existing derived name (nothing
    | changes for them). The `tenant_id` is validated as a ULID and the
    | `database` as a MySQL identifier, and the name may never be the central
    | platform database. This mode never calls the cPanel UAPI.
    |
    | Connection details (host/port/user/password) come from the `tenant`
    | connection in config/database.php — TENANT_DB_HOST, TENANT_DB_PORT,
    | TENANT_DB_USERNAME, TENANT_DB_PASSWORD.
    |
    */
    'manual_database' => [
        'database' => env('TENANT_DB'),
        'tenant_id' => env('TENANT_DB_TENANT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant migrations
    |--------------------------------------------------------------------------
    |
    | Tenant-owned tables live in their own directory so the central `migrate`
    | never creates clinical tables centrally, and tenant migrations never run
    | against the central database.
    |
    */
    'migration_path' => database_path('migrations/tenant'),

    /*
    |--------------------------------------------------------------------------
    | Provisioning connection
    |--------------------------------------------------------------------------
    |
    | Creating/dropping a tenant database needs privileges the runtime tenant
    | credential deliberately does not have. Provisioning runs through a
    | controlled console command using this dedicated connection, which
    | defaults to the central connection.
    |
    */
    'provisioning_connection' => env('TENANCY_PROVISIONING_CONNECTION', env('DB_CONNECTION', 'sqlite')),

    /*
    |--------------------------------------------------------------------------
    | cPanel provisioning adapter
    |--------------------------------------------------------------------------
    |
    | When `enabled` is true, tenant databases are created through the cPanel
    | UAPI instead of raw `CREATE DATABASE`. The cPanel account owns the
    | privileged DDL and grants the RESTRICTED runtime user privileges on the
    | tenant database only — the Laravel runtime credential is never given a
    | global CREATE privilege. These credentials are server-side only.
    |
    | `username` is the cPanel account (also the database name prefix cPanel
    | adds). `runtime_user` is the unprefixed MySQL user the runtime connection
    | authenticates as; cPanel grants it access to each tenant database.
    |
    | `allowed_hosts` / `allowed_ports` restrict the UAPI endpoint to the
    | staging host(s) and port(s) the server permits. Both are REQUIRED: an
    | empty list never means "any host/port", so a mis-set or hostile Global
    | Settings value can never point the API token at another server.
    |
    | No value here belongs in source control: every field is environment-driven.
    |
    */
    'cpanel' => [
        'enabled' => env('CPANEL_PROVISIONING_ENABLED', false),
        'host' => env('CPANEL_HOST'),
        'port' => env('CPANEL_PORT', 2083),
        'username' => env('CPANEL_USERNAME'),
        'api_token' => env('CPANEL_API_TOKEN'),
        'scheme' => env('CPANEL_SCHEME', 'https'),
        'verify_tls' => env('CPANEL_VERIFY_TLS', true),
        'timeout' => env('CPANEL_TIMEOUT', 20),
        'runtime_user' => env('CPANEL_RUNTIME_DB_USER'),

        // Comma-separated allowlists (e.g. "cpanel.staging.example" and "2083").
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CPANEL_ALLOWED_HOSTS', '')),
        ))),
        'allowed_ports' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('CPANEL_ALLOWED_PORTS', '')),
        ))),
    ],

];
