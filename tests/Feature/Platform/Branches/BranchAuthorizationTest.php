<?php

namespace Tests\Feature\Platform\Branches;

use App\Filament\Clinic\Resources\TenantBranchResource;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Contracts\AuthorizesBranchAccess;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Models\TenantBranch;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 2B — branch authorization & isolation.
 *
 * Every decision must be re-derived from trusted data: the central membership /
 * role tables for the identity and the tenant's OWN isolated database for the
 * branches. A branch id (or tenant id) supplied by the browser is never
 * authority, and one tenant can never reach another tenant's branches.
 */
class BranchAuthorizationTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    private function branches(): AuthorizesBranchAccess
    {
        return app(AuthorizesBranchAccess::class);
    }

    private function context(): ManagesBranchContext
    {
        return app(ManagesBranchContext::class);
    }

    private function owner(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    private function staff(Tenant $tenant, string $role = RoleKey::RECEPTIONIST): User
    {
        $user = User::factory()->role($role)->create();
        $this->giveMembership($user, $tenant, [$role]);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD authorization
    |--------------------------------------------------------------------------
    */

    public function test_a_management_role_may_administer_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->assertTrue($this->branches()->canManageBranches($owner, $tenant->getKey()));
        $this->assertTrue($this->context()->canManageBranches($owner));

        $this->actingAs($owner);

        $this->assertTrue(TenantBranchResource::canViewAny());
        $this->assertTrue(TenantBranchResource::canCreate());

        $branch = $this->createBranch($tenant);
        $this->assertTrue(TenantBranchResource::canEdit($branch));
        // Deactivation is non-destructive: branches are never deleted.
        $this->assertFalse(TenantBranchResource::canDelete($branch));
    }

    public function test_a_non_management_role_may_not_administer_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = $this->staff($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->assertFalse($this->branches()->canManageBranches($staff, $tenant->getKey()));
        $this->assertFalse($this->context()->canManageBranches($staff));

        $this->actingAs($staff);

        $this->assertFalse(TenantBranchResource::canViewAny());
        $this->assertFalse(TenantBranchResource::canCreate());
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-tenant isolation
    |--------------------------------------------------------------------------
    */

    public function test_tenant_a_cannot_reach_tenant_b_branches(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
        $ownerA = $this->owner($tenantA);

        $this->provisionTenantDatabase($tenantA);
        $this->provisionTenantDatabase($tenantB);

        $branchB = $this->createBranch($tenantB, ['name' => 'Cabang B']);

        // The identity is not a member of Tenant B at all.
        $this->assertFalse($this->branches()->canAccessBranch($ownerA, $tenantB->getKey(), $branchB->getKey()));

        // ...and the branch id can never be reached through Tenant A either.
        $this->assertFalse($this->branches()->canAccessBranch($ownerA, $tenantA->getKey(), $branchB->getKey()));

        $branchA = $this->createBranch($tenantA, ['name' => 'Cabang A']);

        $this->assertContains($branchA->getKey(), $this->branches()->accessibleBranchIds($ownerA, $tenantA->getKey()));
        $this->assertNotContains($branchB->getKey(), $this->branches()->accessibleBranchIds($ownerA, $tenantA->getKey()));
    }

    public function test_a_manager_sees_only_the_active_branches_of_its_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $alpha = $this->createBranch($tenant, ['name' => 'Alpha']);
        $beta = $this->createBranch($tenant, ['name' => 'Beta']);
        $suspended = $this->createBranch($tenant, ['name' => 'Suspended', 'status' => TenantBranch::STATUS_SUSPENDED]);

        $ids = $this->branches()->accessibleBranchIds($owner, $tenant->getKey());

        $this->assertSame([$alpha->getKey(), $beta->getKey()], $ids);
        $this->assertNotContains($suspended->getKey(), $ids);
    }

    /*
    |--------------------------------------------------------------------------
    | Staff may only reach assigned active branches
    |--------------------------------------------------------------------------
    */

    public function test_a_staff_member_may_reach_only_the_branches_it_is_assigned_to(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $staff = $this->staff($tenant);
        $this->provisionTenantDatabase($tenant);

        $assigned = $this->createBranch($tenant, ['name' => 'Assigned']);
        $other = $this->createBranch($tenant, ['name' => 'Other']);

        $this->assignStaff($owner, $staff, $assigned->getKey());

        $this->assertTrue($this->branches()->canAccessBranch($staff, $tenant->getKey(), $assigned->getKey()));
        $this->assertFalse($this->branches()->canAccessBranch($staff, $tenant->getKey(), $other->getKey()));
        $this->assertSame([$assigned->getKey()], $this->branches()->accessibleBranchIds($staff, $tenant->getKey()));
    }

    public function test_a_revoked_assignment_denies_branch_access(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $staff = $this->staff($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $this->assignStaff($owner, $staff, $branch->getKey());
        $this->assertTrue($this->branches()->canAccessBranch($staff, $tenant->getKey(), $branch->getKey()));

        $this->revokeStaff($owner, $staff, $branch->getKey());

        $this->assertFalse($this->branches()->canAccessBranch($staff, $tenant->getKey(), $branch->getKey()));
        $this->assertSame([], $this->branches()->accessibleBranchIds($staff, $tenant->getKey()));
    }

    public function test_a_suspended_branch_denies_access(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);

        $branch->forceFill(['status' => TenantBranch::STATUS_SUSPENDED])->save();

        $this->assertFalse($this->branches()->canAccessBranch($owner, $tenant->getKey(), $branch->getKey()));
        $this->assertSame([], $this->branches()->accessibleBranchIds($owner, $tenant->getKey()));
    }

    public function test_a_fabricated_or_malformed_branch_id_is_never_accessible(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->createBranch($tenant);

        $this->assertFalse($this->branches()->canAccessBranch($owner, $tenant->getKey(), (string) Str::ulid()));
        $this->assertFalse($this->branches()->canAccessBranch($owner, $tenant->getKey(), 'not-a-branch'));
    }

    /*
    |--------------------------------------------------------------------------
    | Connection isolation
    |--------------------------------------------------------------------------
    */

    public function test_branch_decisions_leave_no_tenant_connection_open(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->createBranch($tenant);
        $this->branches()->accessibleBranchIds($owner, $tenant->getKey());

        $this->tenantDatabases()->forget();

        $this->assertNull($this->tenantDatabases()->current());
        $this->assertNull(config('database.connections.tenant.database'));
    }
}
