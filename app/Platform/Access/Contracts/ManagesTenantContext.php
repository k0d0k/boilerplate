<?php

namespace App\Platform\Access\Contracts;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\TenantOption;

/**
 * Secure tenant context resolution (Phase 1B-2B).
 *
 * The context is the *verified* tenant an authenticated Clinic identity is
 * currently operating in. It is resolved server-side from the central
 * membership tables and persisted in the authenticated session — a tenant id
 * supplied by the browser is never trusted as authority.
 *
 * Guarantees every implementation must keep:
 *  - only `active` memberships of `active` tenants are eligible;
 *  - the stored selection is revalidated against the current membership and
 *    tenant status on every read, and cleared the moment it becomes invalid;
 *  - exactly one eligible membership may be auto-selected after verification;
 *  - with several eligible memberships no tenant is ever chosen arbitrarily —
 *    an explicit selection is required;
 *  - with no eligible membership there is no context at all (Clinic operations
 *    are denied by the caller).
 *
 * A selected tenant grants nothing by itself: permissions are still resolved
 * per tenant through the central authorization services.
 */
interface ManagesTenantContext
{
    /**
     * Every tenant the identity may switch into, as display options.
     *
     * @return list<TenantOption>
     */
    public function eligibleOptions(User $user): array;

    public function hasEligibleMembership(User $user): bool;

    public function hasMultipleEligibleMemberships(User $user): bool;

    /**
     * The verified tenant of the current context, or null when none applies.
     */
    public function currentTenant(User $user): ?Tenant;

    /**
     * The verified tenant id of the current context, or null when none applies.
     *
     * Revalidates the stored selection on every call and auto-selects the single
     * eligible membership (after verification) when no valid selection is held.
     */
    public function currentTenantId(User $user): ?string;

    public function hasVerifiedContext(User $user): bool;

    /**
     * True when a choice is outstanding: several eligible memberships and no
     * verified selection yet.
     */
    public function requiresExplicitSelection(User $user): bool;

    /**
     * Verify and persist an explicit selection. Returns false (and clears any
     * stale value) when the tenant is malformed, foreign, revoked, suspended or
     * belongs to a suspended tenant.
     */
    public function select(User $user, ?string $tenantId): bool;

    /**
     * True when the given tenant id is the current verified context. Used by
     * tenant-scoped actions so a stale request cannot act in another tenant's
     * context after the session selection changed elsewhere.
     */
    public function matchesCurrent(User $user, ?string $tenantId): bool;

    /**
     * Forget the stored selection (logout, identity change, revocation).
     */
    public function clear(): void;
}
