<?php

namespace Tests\Feature\Database;

use App\Models\Permission;
use App\Models\PrincipalRoleAssignment;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1B-2A-R — production seeding safety.
 *
 * Normal seeding (the entry point `db:seed` runs) must never introduce an
 * identity with predictable or default credentials. Only the idempotent
 * authorization catalog is created.
 */
class SeedingSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seeding_creates_no_demo_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, TenantMembership::query()->count());
        $this->assertSame(0, PrincipalRoleAssignment::query()->count());
    }

    public function test_production_seeding_never_creates_the_predictable_test_identity(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(User::query()->where('email', 'test@example.com')->exists());
    }

    public function test_production_seeding_still_installs_the_authorization_catalog(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(RoleKey::ALL), Role::query()->count());
        $this->assertSame(count(PermissionKey::ALL), Permission::query()->count());
    }

    public function test_rbac_seeding_is_idempotent(): void
    {
        $this->seed(RbacSeeder::class);

        $roleCount = Role::query()->count();
        $permissionCount = Permission::query()->count();
        $grantCount = Role::query()->with('permissions')->get()->sum(fn (Role $role) => $role->permissions->count());

        $this->seed(RbacSeeder::class);

        $this->assertSame($roleCount, Role::query()->count());
        $this->assertSame($permissionCount, Permission::query()->count());
        $this->assertSame(
            $grantCount,
            Role::query()->with('permissions')->get()->sum(fn (Role $role) => $role->permissions->count()),
        );
    }
}
