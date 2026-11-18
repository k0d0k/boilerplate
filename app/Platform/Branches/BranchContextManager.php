<?php

namespace App\Platform\Branches;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\TenantContextManager;
use App\Platform\Branches\Contracts\AuthorizesBranchAccess;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Session\Store;

/**
 * Session-backed secure branch context (Phase 2B).
 *
 * Mirrors {@see TenantContextManager}:
 *  1. eligibility is re-derived from the tenant database on every call — an
 *     active branch the identity may access in the verified current tenant;
 *  2. the stored selection is bound to the tenant it was made in. Switching
 *     tenant, a revoked assignment or a suspended/deleted branch invalidates it
 *     immediately and clears it;
 *  3. exactly one accessible branch is auto-selected after verification; with
 *     several, an explicit selection is required; with none there is no branch
 *     context (branch-scoped operations are denied).
 *
 * Only the tenant id and the branch id are persisted; both are revalidated on
 * every read, so a stale or forged value can never survive.
 */
final class BranchContextManager implements ManagesBranchContext
{
    /** Session key holding the tenant the branch selection belongs to. */
    public const SESSION_TENANT_KEY = 'lba.branch_context.tenant_id';

    /** Session key holding the verified branch id. */
    public const SESSION_BRANCH_KEY = 'lba.branch_context.branch_id';

    public function __construct(
        private readonly ManagesTenantContext $tenants,
        private readonly AuthorizesBranchAccess $branches,
    ) {}

    public function eligibleOptions(User $user): array
    {
        $tenantId = $this->tenants->currentTenantId($user);

        if ($tenantId === null) {
            return [];
        }

        return array_map(
            static fn (TenantBranch $branch) => new BranchOption(
                id: (string) $branch->getKey(),
                name: (string) $branch->name,
                code: $branch->code !== null ? (string) $branch->code : null,
            ),
            $this->branches->accessibleBranches($user, $tenantId),
        );
    }

    public function hasEligibleBranch(User $user): bool
    {
        return $this->accessibleBranchIds($user) !== [];
    }

    public function hasMultipleEligibleBranches(User $user): bool
    {
        return count($this->accessibleBranchIds($user)) > 1;
    }

    public function currentBranch(User $user): ?TenantBranch
    {
        $branchId = $this->currentBranchId($user);
        $tenantId = $this->tenants->currentTenantId($user);

        if ($branchId === null || $tenantId === null) {
            return null;
        }

        foreach ($this->branches->accessibleBranches($user, $tenantId) as $branch) {
            if ((string) $branch->getKey() === $branchId) {
                return $branch;
            }
        }

        return null;
    }

    public function currentBranchId(User $user): ?string
    {
        $tenantId = $this->tenants->currentTenantId($user);

        if ($tenantId === null) {
            $this->clear();

            return null;
        }

        $stored = $this->stored();

        if ($stored !== null) {
            if ($stored['tenant'] === $tenantId
                && $this->branches->canAccessBranch($user, $tenantId, $stored['branch'])) {
                return $stored['branch'];
            }

            // Tenant switched, assignment revoked, or branch suspended/deleted:
            // drop it immediately so it can never be reused.
            $this->clear();
        }

        $accessible = $this->branches->accessibleBranchIds($user, $tenantId);

        if (count($accessible) === 1) {
            $branchId = $accessible[0];
            $this->store($tenantId, $branchId);

            return $branchId;
        }

        return null;
    }

    public function hasBranchContext(User $user): bool
    {
        return $this->currentBranchId($user) !== null;
    }

    public function requiresExplicitSelection(User $user): bool
    {
        return $this->hasMultipleEligibleBranches($user)
            && $this->currentBranchId($user) === null;
    }

    public function canManageBranches(User $user): bool
    {
        $tenantId = $this->tenants->currentTenantId($user);

        return $tenantId !== null && $this->branches->canManageBranches($user, $tenantId);
    }

    public function select(User $user, ?string $branchId): bool
    {
        $tenantId = $this->tenants->currentTenantId($user);

        if ($tenantId === null
            || $branchId === null
            || ! $this->branches->canAccessBranch($user, $tenantId, $branchId)) {
            $this->clear();

            return false;
        }

        $this->store($tenantId, $branchId);

        return true;
    }

    public function matchesCurrent(User $user, ?string $branchId): bool
    {
        if ($branchId === null || $branchId === '') {
            return false;
        }

        return $this->currentBranchId($user) === $branchId;
    }

    public function clear(): void
    {
        $this->session()->forget([self::SESSION_TENANT_KEY, self::SESSION_BRANCH_KEY]);
    }

    /**
     * Ids of the branches accessible to the identity in the current tenant.
     *
     * @return list<string>
     */
    private function accessibleBranchIds(User $user): array
    {
        $tenantId = $this->tenants->currentTenantId($user);

        if ($tenantId === null) {
            return [];
        }

        return $this->branches->accessibleBranchIds($user, $tenantId);
    }

    /**
     * @return array{tenant: string, branch: string}|null
     */
    private function stored(): ?array
    {
        $tenant = $this->session()->get(self::SESSION_TENANT_KEY);
        $branch = $this->session()->get(self::SESSION_BRANCH_KEY);

        if (! is_string($tenant) || $tenant === '' || ! is_string($branch) || $branch === '') {
            return null;
        }

        return ['tenant' => $tenant, 'branch' => $branch];
    }

    private function store(string $tenantId, string $branchId): void
    {
        $this->session()->put(self::SESSION_TENANT_KEY, $tenantId);
        $this->session()->put(self::SESSION_BRANCH_KEY, $branchId);
    }

    private function session(): Store
    {
        return app('session')->driver();
    }
}
