<?php

namespace App\Platform\Branches;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\AuthorizesBranchAccess;
use App\Platform\Branches\Models\BranchStaffAssignment;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tenant-database-backed branch authorization (Phase 2B).
 *
 * Derives every decision from trusted data:
 *  - the identity's ACTIVE central membership in the tenant (never a client id);
 *  - the tenant's OWN isolated database for branches and staff assignments.
 *
 * Only the requested tenant's own database is ever read, and only when the
 * identity is an active member of it. Because a Livewire update request bypasses
 * the panel's tenant-binding middleware, the database is bound transiently for
 * the read (when it is not already the bound one) and released again — even on
 * failure — so a read can never cross into another tenant's database and never
 * leaks a connection.
 */
final class BranchAccessAuthorizer implements AuthorizesBranchAccess
{
    public function __construct(
        private readonly AuthorizesTenantMembership $memberships,
        private readonly ChecksPermissions $permissions,
        private readonly ResolvesTenantDatabase $databases,
    ) {}

    public function canManageBranches(User $user, string $tenantId): bool
    {
        if ($tenantId === '' || ! $this->memberships->isMemberOf($user, $tenantId)) {
            return false;
        }

        return $this->permissions->allows(
            $user,
            PermissionKey::BRANCH_OPERATIONS_MANAGE,
            AccessContext::forTenant($tenantId),
        );
    }

    public function accessibleBranches(User $user, string $tenantId): array
    {
        if ($tenantId === '' || ! $this->memberships->isMemberOf($user, $tenantId)) {
            return [];
        }

        $wasBound = $this->databases->current() === $tenantId;

        try {
            if (! $wasBound) {
                $this->databases->configure($tenantId);
            }

            $branches = TenantBranch::query()->active()->orderBy('name')->get();

            if ($this->canManageBranches($user, $tenantId)) {
                return $branches->values()->all();
            }

            $assigned = BranchStaffAssignment::query()
                ->active()
                ->where('user_id', $user->getKey())
                ->pluck('branch_id')
                ->all();

            if ($assigned === []) {
                return [];
            }

            return $branches->whereIn('id', $assigned)->values()->all();
        } catch (Throwable) {
            // Fail closed: no verified tenant connection => no branches.
            return [];
        } finally {
            if (! $wasBound) {
                $this->databases->forget();
            }
        }
    }

    public function accessibleBranchIds(User $user, string $tenantId): array
    {
        return array_map(
            static fn (TenantBranch $branch): string => (string) $branch->getKey(),
            $this->accessibleBranches($user, $tenantId),
        );
    }

    public function canAccessBranch(User $user, string $tenantId, string $branchId): bool
    {
        if ($tenantId === '' || $branchId === '' || ! Str::isUlid($branchId)) {
            return false;
        }

        return in_array($branchId, $this->accessibleBranchIds($user, $tenantId), true);
    }
}
