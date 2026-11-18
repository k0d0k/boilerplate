<?php

namespace Tests\Feature\Platform\Branches;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\RoleKey;
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
 * Phase 2B — verified branch context.
 *
 * The context mirrors the tenant lifecycle in {@see BranchContextManager}: a
 * single accessible branch is auto-selected after verification, several require
 * an explicit choice, and a stored selection is revalidated on every read — a
 * tenant switch, a revoked assignment or a suspended branch clears it at once.
 * A branch id supplied by the client is never trusted.
 *
 * Like the request lifecycle, the tenant database is (re)bound whenever the
 * tenant context changes before the branch context is read.
 */
class BranchContextTest extends TestCase
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

    private function context(): ManagesBranchContext
    {
        return app(ManagesBranchContext::class);
    }

    private function tenants(): ManagesTenantContext
    {
        return app(ManagesTenantContext::class);
    }

    private function owner(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Selection states
    |--------------------------------------------------------------------------
    */

    public function test_a_single_accessible_branch_is_auto_selected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);

        $this->assertSame($branch->getKey(), $this->context()->currentBranchId($owner));
        $this->assertTrue($this->context()->hasBranchContext($owner));
        $this->assertFalse($this->context()->requiresExplicitSelection($owner));
    }

    public function test_multiple_branches_require_an_explicit_selection(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->createBranch($tenant, ['name' => 'Alpha']);
        $this->createBranch($tenant, ['name' => 'Beta']);

        $this->assertNull($this->context()->currentBranchId($owner));
        $this->assertTrue($this->context()->hasMultipleEligibleBranches($owner));
        $this->assertTrue($this->context()->requiresExplicitSelection($owner));
        $this->assertCount(2, $this->context()->eligibleOptions($owner));
    }

    public function test_an_explicit_selection_of_an_accessible_branch_is_verified(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $chosen = $this->createBranch($tenant, ['name' => 'Alpha']);
        $this->createBranch($tenant, ['name' => 'Beta']);

        $this->assertTrue($this->context()->select($owner, $chosen->getKey()));
        $this->assertSame($chosen->getKey(), $this->context()->currentBranchId($owner));
        $this->assertTrue($this->context()->matchesCurrent($owner, $chosen->getKey()));
    }

    public function test_no_branch_context_when_the_tenant_has_no_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->tenantDatabases()->configure($tenant->getKey());

        $this->assertNull($this->context()->currentBranchId($owner));
        $this->assertFalse($this->context()->hasEligibleBranch($owner));
        // ...yet the identity may still set up the tenant's first branch.
        $this->assertTrue($this->context()->canManageBranches($owner));
    }

    /*
    |--------------------------------------------------------------------------
    | Client-supplied ids are never trusted
    |--------------------------------------------------------------------------
    */

    public function test_a_fabricated_branch_id_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $this->createBranch($tenant, ['name' => 'Alpha']);
        $this->createBranch($tenant, ['name' => 'Beta']);

        $this->assertFalse($this->context()->select($owner, (string) Str::ulid()));
        $this->assertNull($this->context()->currentBranchId($owner));
    }

    public function test_a_foreign_tenant_branch_id_is_rejected(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
        $owner = $this->owner($tenantA);

        $this->provisionTenantDatabase($tenantA);
        $this->provisionTenantDatabase($tenantB);

        $ownBranch = $this->createBranch($tenantA);
        $foreignBranch = $this->createBranch($tenantB);

        $this->tenantDatabases()->configure($tenantA->getKey());

        $this->assertFalse($this->context()->select($owner, $foreignBranch->getKey()));
        // The rejected id never becomes the context; the own branch stands.
        $this->assertSame($ownBranch->getKey(), $this->context()->currentBranchId($owner));
    }

    /*
    |--------------------------------------------------------------------------
    | Context invalidation
    |--------------------------------------------------------------------------
    */

    public function test_switching_tenant_clears_the_wrong_branch_context(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
        $owner = $this->owner($tenantA);

        $this->provisionTenantDatabase($tenantA);
        $this->provisionTenantDatabase($tenantB);

        $branchA = $this->createBranch($tenantA);
        $branchB = $this->createBranch($tenantB);

        // Single membership: Tenant A is auto-selected and its only branch too.
        $this->assertSame($branchA->getKey(), $this->context()->currentBranchId($owner));

        // A second membership is granted and the identity switches tenant.
        $this->giveMembership($owner, $tenantB, [RoleKey::TENANT_OWNER]);
        $this->assertTrue($this->tenants()->select($owner, $tenantB->getKey()));

        // The request lifecycle rebinds the database for the new tenant...
        $this->tenantDatabases()->configure($tenantB->getKey());

        // ...and the stale Tenant A branch is gone, replaced by Tenant B's.
        $current = $this->context()->currentBranchId($owner);
        $this->assertNotSame($branchA->getKey(), $current);
        $this->assertSame($branchB->getKey(), $current);

        // Switching back revalidates again.
        $this->assertTrue($this->tenants()->select($owner, $tenantA->getKey()));
        $this->tenantDatabases()->configure($tenantA->getKey());
        $this->assertSame($branchA->getKey(), $this->context()->currentBranchId($owner));
    }

    public function test_a_suspended_branch_invalidates_the_stored_context(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $this->assertSame($branch->getKey(), $this->context()->currentBranchId($owner));

        $branch->forceFill(['status' => TenantBranch::STATUS_SUSPENDED])->save();

        $this->assertNull($this->context()->currentBranchId($owner));
        $this->assertFalse($this->context()->hasEligibleBranch($owner));
    }

    public function test_a_revoked_assignment_invalidates_the_stored_context(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $staff = User::factory()->role(RoleKey::RECEPTIONIST)->create();
        $this->giveMembership($staff, $tenant, [RoleKey::RECEPTIONIST]);
        $this->provisionTenantDatabase($tenant);

        $assigned = $this->createBranch($tenant, ['name' => 'Assigned']);
        $this->createBranch($tenant, ['name' => 'Other']);

        $this->assignStaff($owner, $staff, $assigned->getKey());
        $this->assertSame($assigned->getKey(), $this->context()->currentBranchId($staff));

        $this->revokeStaff($owner, $staff, $assigned->getKey());

        $this->assertNull($this->context()->currentBranchId($staff));
        $this->assertFalse($this->context()->hasEligibleBranch($staff));
    }
}
