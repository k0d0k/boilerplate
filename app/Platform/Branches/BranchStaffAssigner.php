<?php

namespace App\Platform\Branches;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\Contracts\AuthorizesBranchAccess;
use App\Platform\Branches\Models\BranchStaffAssignment;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Closure;
use RuntimeException;

/**
 * Tenant-scoped staff branch assignment (Phase 2B).
 *
 * Assigning or revoking a branch must never be possible from a forged request:
 * the acting identity must hold the branch-administration role in the verified
 * current tenant, and the staff identity must hold an ACTIVE central membership
 * with a permitted role in that same tenant. The assignment itself is written to
 * the tenant's isolated database, so it can only reference that tenant's
 * branches, and it links to the central user id with a plain string (no
 * cross-database foreign key).
 *
 * Because a Livewire update request bypasses the panel's tenant-binding
 * middleware, the verified tenant database is bound transiently for the write
 * (when it is not already the bound one) and released again — even on failure —
 * so the write can never target another tenant's database or leak a connection.
 */
final class BranchStaffAssigner
{
    public function __construct(
        private readonly ManagesTenantContext $tenants,
        private readonly AuthorizesBranchAccess $branches,
        private readonly ResolvesTenantDatabase $databases,
    ) {}

    /**
     * Grant (or re-activate) a staff identity's access to one branch.
     */
    public function assign(User $actor, User $staff, string $branchId): BranchStaffAssignment
    {
        $tenantId = $this->authorizeActor($actor, $branchId);

        $this->assertStaffEligible($staff, $tenantId);

        return $this->withinVerifiedTenant($tenantId, fn () => BranchStaffAssignment::query()->updateOrCreate(
            ['branch_id' => $branchId, 'user_id' => (string) $staff->getKey()],
            [
                'status' => BranchStaffAssignment::STATUS_ACTIVE,
                'assigned_at' => now(),
                'revoked_at' => null,
            ],
        ));
    }

    /**
     * Revoke a staff identity's access to one branch (non-destructive).
     */
    public function revoke(User $actor, User $staff, string $branchId): bool
    {
        $tenantId = $this->authorizeActor($actor, $branchId);

        return $this->withinVerifiedTenant($tenantId, function () use ($staff, $branchId): bool {
            $assignment = BranchStaffAssignment::query()
                ->where('branch_id', $branchId)
                ->where('user_id', (string) $staff->getKey())
                ->first();

            if ($assignment === null) {
                return false;
            }

            $assignment->forceFill([
                'status' => BranchStaffAssignment::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * The acting identity must be an authorized manager of the verified current
     * tenant AND be able to access the branch; returns the verified tenant id.
     */
    private function authorizeActor(User $actor, string $branchId): string
    {
        $tenantId = $this->tenants->currentTenantId($actor);

        if ($tenantId === null || ! $this->branches->canManageBranches($actor, $tenantId)) {
            throw new RuntimeException('Branch administration is not permitted in the current tenant.');
        }

        if (! $this->branches->canAccessBranch($actor, $tenantId, $branchId)) {
            throw new RuntimeException('The branch is not an active branch of the current tenant.');
        }

        return $tenantId;
    }

    /**
     * The staff identity must hold an ACTIVE membership of the ACTIVE tenant,
     * with at least one permitted (tenant/patient) role. A revoked, suspended or
     * unknown membership — or a membership with no role — is refused.
     */
    private function assertStaffEligible(User $staff, string $tenantId): void
    {
        if (! $staff->is_active) {
            throw new RuntimeException('The staff identity is not active.');
        }

        $membership = TenantMembership::query()
            ->where('user_id', $staff->getKey())
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with('roles')
            ->first();

        if ($membership === null) {
            throw new RuntimeException('The staff identity has no active membership in the current tenant.');
        }

        if ($membership->roles->isEmpty()) {
            throw new RuntimeException('The staff identity holds no role in the current tenant.');
        }
    }

    /**
     * Run the callback against the verified tenant's OWN database, binding it
     * transiently when it is not already the bound connection and releasing it
     * afterwards (even on failure), so no connection leaks.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withinVerifiedTenant(string $tenantId, Closure $callback): mixed
    {
        $wasBound = $this->databases->current() === $tenantId;

        try {
            if (! $wasBound) {
                $this->databases->configure($tenantId);
            }

            return $callback();
        } finally {
            if (! $wasBound) {
                $this->databases->forget();
            }
        }
    }
}
