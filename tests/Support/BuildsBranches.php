<?php

namespace Tests\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Branches\BranchStaffAssigner;
use App\Platform\Branches\Models\BranchStaffAssignment;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Support\Str;

/**
 * Phase 2B test helpers — synthetic branches and staff assignments.
 *
 * Branches and assignments are written directly into a provisioned tenant
 * database; only synthetic data is ever used (DECISIONS.md PRV-01).
 *
 * The consuming test must also use {@see BuildsTenantDatabases} (for
 * `tenantDatabases()`) and must have provisioned the tenant's database before
 * calling these helpers.
 */
trait BuildsBranches
{
    /**
     * Create a branch in the tenant's isolated database and leave that database
     * bound, so subsequent branch-aware assertions run against it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createBranch(Tenant $tenant, array $attributes = []): TenantBranch
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        return TenantBranch::query()->create(array_merge([
            'name' => 'Cabang '.Str::upper(Str::random(4)),
            'code' => 'BR-'.Str::upper(Str::random(5)),
            'status' => TenantBranch::STATUS_ACTIVE,
        ], $attributes));
    }

    protected function assignStaff(User $actor, User $staff, string $branchId): BranchStaffAssignment
    {
        return app(BranchStaffAssigner::class)->assign($actor, $staff, $branchId);
    }

    protected function revokeStaff(User $actor, User $staff, string $branchId): bool
    {
        return app(BranchStaffAssigner::class)->revoke($actor, $staff, $branchId);
    }
}
