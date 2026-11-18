<?php

namespace App\Platform\Tenancy\Contracts;

use App\Platform\Tenancy\Exceptions\TenantAccessDeniedException;

/**
 * Resolves and switches the runtime tenant database connection (Phase 2A).
 *
 * Exactly one tenant database may be active at a time. Switching always purges
 * the previous connection first, so a later query can never reuse a stale
 * connection pointed at another tenant's database.
 *
 * Every implementation must fail closed: an unknown, suspended or deleted
 * tenant never resolves to a usable connection.
 */
interface ResolvesTenantDatabase
{
    /**
     * Point the tenant connection at the given tenant's isolated database and
     * make it current. Returns the connection name to use.
     *
     * @throws TenantAccessDeniedException
     */
    public function configure(string $tenantId): string;

    /**
     * The connection name tenant-owned models use.
     */
    public function connectionName(): string;

    /**
     * The currently active tenant id, or null when no tenant is active.
     */
    public function current(): ?string;

    /**
     * Close and forget the active tenant connection so no tenant data can be
     * reused by the next request, job or component.
     */
    public function forget(): void;
}
