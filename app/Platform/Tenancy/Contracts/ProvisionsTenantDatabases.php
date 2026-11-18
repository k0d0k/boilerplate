<?php

namespace App\Platform\Tenancy\Contracts;

/**
 * Provisions and removes the physical storage for a tenant database (Phase 2A).
 *
 * Provisioning runs with privileged, controlled credentials through console
 * commands — never from an unauthenticated browser request. Runtime queries use
 * the restricted tenant credential instead.
 */
interface ProvisionsTenantDatabases
{
    /**
     * Create the tenant's isolated database storage if it does not exist.
     */
    public function provision(string $tenantId): void;

    /**
     * Remove the tenant's isolated database storage.
     */
    public function drop(string $tenantId): void;

    /**
     * True when the tenant's isolated database storage already exists.
     */
    public function exists(string $tenantId): bool;
}
