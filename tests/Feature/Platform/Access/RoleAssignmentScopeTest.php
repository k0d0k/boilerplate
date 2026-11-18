<?php

namespace Tests\Feature\Platform\Access;

use App\Models\PrincipalRoleAssignment;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ResolvesRoles;
use App\Platform\Access\Exceptions\InvalidRoleAssignmentException;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1B-2A-R — strict role scope and implicit-activation hardening.
 *
 * Role assignments are validated server-side at the model layer, never by UI
 * filtering, so a malformed or cross-scope assignment cannot be persisted — and
 * even a row written directly to the database cannot grant permissions.
 */
class RoleAssignmentScopeTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    private ChecksPermissions $permissions;

    private ResolvesRoles $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->permissions = app(ChecksPermissions::class);
        $this->roles = app(ResolvesRoles::class);
    }

    public function test_principal_assignment_rejects_a_tenant_role(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidRoleAssignmentException::class);

        PrincipalRoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $this->roleId(RoleKey::TENANT_OWNER),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_principal_assignment_rejects_the_machine_only_role(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidRoleAssignmentException::class);

        PrincipalRoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $this->roleId(RoleKey::AI_SERVICE),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_principal_assignment_rejects_an_unknown_role_id(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidRoleAssignmentException::class);

        PrincipalRoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => (string) Str::ulid(),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_principal_assignment_accepts_a_platform_role_and_stays_inactive(): void
    {
        $user = User::factory()->create();

        $assignment = PrincipalRoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $this->roleId(RoleKey::PRINCIPAL_FINANCE),
        ]);

        $this->assertSame(PrincipalRoleAssignment::STATUS_PENDING, $assignment->refresh()->status);
        $this->assertFalse($assignment->isActive());
    }

    public function test_membership_rejects_a_principal_role(): void
    {
        $user = User::factory()->create();
        $membership = TenantMembership::factory()
            ->forUser($user)
            ->forTenant(Tenant::factory()->create())
            ->active()
            ->create();

        $this->expectException(InvalidRoleAssignmentException::class);

        $membership->roles()->attach($this->roleId(RoleKey::PRINCIPAL_SUPER_ADMIN));
    }

    public function test_membership_rejects_the_machine_only_role(): void
    {
        $user = User::factory()->create();
        $membership = TenantMembership::factory()
            ->forUser($user)
            ->forTenant(Tenant::factory()->create())
            ->active()
            ->create();

        $this->expectException(InvalidRoleAssignmentException::class);

        $membership->roles()->attach($this->roleId(RoleKey::AI_SERVICE));
    }

    public function test_membership_rejects_an_unknown_role_id(): void
    {
        $user = User::factory()->create();
        $membership = TenantMembership::factory()
            ->forUser($user)
            ->forTenant(Tenant::factory()->create())
            ->active()
            ->create();

        $this->expectException(InvalidRoleAssignmentException::class);

        $membership->roles()->attach((string) Str::ulid());
    }

    public function test_membership_accepts_a_tenant_role(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $membership = $this->giveMembership($user, $tenant, [RoleKey::REVIEWER]);

        $this->assertSame(
            [RoleKey::REVIEWER],
            $this->roles->tenantRoleKeys($user, $tenant->getKey()),
        );
        $this->assertTrue($membership->isActive());
    }

    public function test_pending_principal_assignment_grants_nothing(): void
    {
        $user = User::factory()->create();

        PrincipalRoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $this->roleId(RoleKey::PRINCIPAL_SUPER_ADMIN),
            'status' => PrincipalRoleAssignment::STATUS_PENDING,
        ]);

        $this->assertSame([], $this->roles->principalRoleKeys($user));
        $this->assertFalse(app(AuthorizesPrincipal::class)->isPrincipal($user));
        $this->assertFalse($this->permissions->allows($user, PermissionKey::PLATFORM_BRAND_MANAGE));
    }

    public function test_invited_membership_grants_nothing(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $membership = TenantMembership::factory()
            ->forUser($user)
            ->forTenant($tenant)
            ->create();

        $membership->roles()->attach($this->roleId(RoleKey::TENANT_OWNER));

        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->status);
        $this->assertSame([], $this->roles->tenantRoleKeys($user, $tenant->getKey()));
        $this->assertSame([], $this->roles->activeTenantIds($user));
        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
    }

    public function test_cross_scope_principal_row_written_outside_the_guard_grants_no_platform_access(): void
    {
        $user = User::factory()->create();

        // Bypass the model guard on purpose: a row that should never exist must
        // still resolve to nothing (deny by default / defense in depth).
        DB::table('principal_role_assignments')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->getKey(),
            'role_id' => $this->roleId(RoleKey::TENANT_OWNER),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->roles->principalRoleKeys($user));
        $this->assertFalse(app(AuthorizesPrincipal::class)->isPrincipal($user));
        $this->assertFalse($this->permissions->allows($user, PermissionKey::PLATFORM_BRAND_MANAGE));
    }

    public function test_cross_scope_membership_role_row_written_outside_the_guard_grants_nothing(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $membership = $this->giveMembership($user, $tenant);

        DB::table('membership_roles')->insert([
            'membership_id' => $membership->getKey(),
            'role_id' => $this->roleId(RoleKey::PRINCIPAL_SUPER_ADMIN),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->roles->tenantRoleKeys($user, $tenant->getKey()));
        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
    }

    public function test_wrong_scope_role_assignment_cannot_grant_permissions(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        // A tenant membership carrying a platform role is rejected outright.
        $membership = TenantMembership::factory()
            ->forUser($user)
            ->forTenant($tenant)
            ->active()
            ->create();

        try {
            $membership->roles()->attach($this->roleId(RoleKey::PRINCIPAL_SUPER_ADMIN));
            $this->fail('A principal role must not be attachable through a membership.');
        } catch (InvalidRoleAssignmentException) {
            // expected
        }

        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::PLATFORM_BRAND_MANAGE,
        ));
        $this->assertFalse($this->permissions->allows(
            $user,
            PermissionKey::EXAM_READ,
            AccessContext::forTenant($tenant->getKey()),
        ));
    }
}
