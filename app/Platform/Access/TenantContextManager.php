<?php

namespace App\Platform\Access;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\Contracts\ValidatesTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Session\Store;

/**
 * Session-backed secure tenant context (Phase 1B-2B).
 *
 * Resolution rules (see {@see ManagesTenantContext}):
 *  1. Eligibility is re-derived from the central tables on every call — an
 *     `active` membership whose tenant is `active`. Revoked, suspended,
 *     invited, unknown and tenant-mismatched memberships are never eligible.
 *  2. A selection stored in the authenticated session is only honoured after it
 *     is revalidated against the identity *and* the tenant status. The moment it
 *     fails (revoked / suspended / deleted tenant, changed identity) it is
 *     cleared, so stale state can never linger.
 *  3. When no valid selection is held and exactly one membership is eligible,
 *     that tenant is auto-selected (server-side verified). With several
 *     eligible memberships nothing is chosen arbitrarily: the caller must ask
 *     the user to choose. With none, there is no context.
 *
 * Tenant vs Brand vs Branch: only the *tenant* id is persisted and authorized.
 * A brand is a display label; a branch is a future, tenant-scoped concept
 * carried by {@see AccessContext}. Neither substitutes for the tenant.
 */
final class TenantContextManager implements ManagesTenantContext
{
    /** Session key holding the verified tenant id. */
    public const SESSION_KEY = 'lba.tenant_context.tenant_id';

    public function __construct(
        private readonly ValidatesTenantScope $scopeValidator,
        private readonly AuthorizesTenantMembership $memberships,
    ) {}

    public function eligibleOptions(User $user): array
    {
        return $this->eligibleMemberships($user)
            ->map(fn (TenantMembership $membership) => new TenantOption(
                id: (string) $membership->tenant_id,
                name: (string) $membership->tenant?->name,
                brandLabel: $this->primaryBrandLabel($membership->tenant),
            ))
            ->all();
    }

    public function hasEligibleMembership(User $user): bool
    {
        return $this->eligibleMemberships($user)->isNotEmpty();
    }

    public function hasMultipleEligibleMemberships(User $user): bool
    {
        return $this->eligibleMemberships($user)->count() > 1;
    }

    public function currentTenant(User $user): ?Tenant
    {
        $tenantId = $this->currentTenantId($user);

        if ($tenantId === null) {
            return null;
        }

        return $this->eligibleMemberships($user)
            ->firstWhere('tenant_id', $tenantId)?->tenant;
    }

    public function currentTenantId(User $user): ?string
    {
        $stored = $this->storedTenantId();

        if ($stored !== null) {
            if ($this->scopeValidator->isValidFor($user, $stored)) {
                return $stored;
            }

            // Revoked / suspended / deleted / foreign for this identity: drop it
            // immediately so it can never be reused.
            $this->clear();
        }

        $eligible = $this->eligibleMemberships($user);

        if ($eligible->count() === 1) {
            $tenantId = (string) $eligible->first()->tenant_id;
            $this->store($tenantId);

            return $tenantId;
        }

        return null;
    }

    public function hasVerifiedContext(User $user): bool
    {
        return $this->currentTenantId($user) !== null;
    }

    public function requiresExplicitSelection(User $user): bool
    {
        return $this->hasMultipleEligibleMemberships($user)
            && $this->currentTenantId($user) === null;
    }

    public function select(User $user, ?string $tenantId): bool
    {
        if ($tenantId === null || ! $this->scopeValidator->isValidFor($user, $tenantId)) {
            $this->clear();

            return false;
        }

        $this->store($tenantId);

        return true;
    }

    public function matchesCurrent(User $user, ?string $tenantId): bool
    {
        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        return $this->currentTenantId($user) === $tenantId;
    }

    public function clear(): void
    {
        $this->session()->forget(self::SESSION_KEY);
    }

    /**
     * Active memberships of active tenants, tenant + brands eager loaded.
     *
     * @return Collection<int, TenantMembership>
     */
    private function eligibleMemberships(User $user): Collection
    {
        if (! $user->is_active) {
            return new Collection;
        }

        return TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn (Builder $query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with('tenant.brands')
            ->get()
            ->sortBy(fn (TenantMembership $membership) => $membership->tenant?->name)
            ->values();
    }

    /**
     * The tenant's primary brand name, falling back to its first brand. A brand
     * is presentational only and never authorizes anything.
     */
    private function primaryBrandLabel(?Tenant $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }

        $brands = $tenant->brands;

        $primary = $brands->first(fn ($brand) => (bool) $brand->pivot->is_primary)
            ?? $brands->first();

        return $primary?->name;
    }

    private function storedTenantId(): ?string
    {
        $value = $this->session()->get(self::SESSION_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function store(string $tenantId): void
    {
        $this->session()->put(self::SESSION_KEY, $tenantId);
    }

    private function session(): Store
    {
        return app('session')->driver();
    }
}
