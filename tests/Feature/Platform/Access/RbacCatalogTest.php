<?php

namespace Tests\Feature\Platform\Access;

use App\Models\Permission;
use App\Models\Role;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1B-2A catalog integrity.
 *
 * The catalog is the single source of truth for roles, permissions and
 * separation of duties; the database must mirror it exactly.
 */
class RbacCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_seeded_roles_match_the_canonical_role_keys(): void
    {
        $seeded = Role::query()->pluck('key')->sort()->values()->all();
        $expected = collect(RoleKey::ALL)->sort()->values()->all();

        $this->assertSame($expected, $seeded);
    }

    public function test_seeded_permissions_match_the_canonical_permission_keys(): void
    {
        $seeded = Permission::query()->pluck('key')->sort()->values()->all();
        $expected = collect(PermissionKey::ALL)->sort()->values()->all();

        $this->assertSame($expected, $seeded);
    }

    public function test_role_permission_assignments_match_the_catalog(): void
    {
        foreach (RbacCatalog::rolePermissions() as $roleKey => $permissionKeys) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $assigned = $role->permissions()->pluck('key')->sort()->values()->all();

            $this->assertSame(
                collect($permissionKeys)->sort()->values()->all(),
                $assigned,
                "Role [{$roleKey}] permissions drifted from the catalog.",
            );
        }
    }

    public function test_permission_scopes_are_consistent_with_the_catalog(): void
    {
        foreach (PermissionKey::ALL as $key) {
            $scope = RbacCatalog::permissionScope($key);

            $this->assertContains($scope, [
                RbacCatalog::SCOPE_PLATFORM,
                RbacCatalog::SCOPE_TENANT,
                RbacCatalog::SCOPE_PATIENT,
            ], "Permission [{$key}] has no resolvable scope.");
        }

        foreach (Permission::query()->get() as $permission) {
            $this->assertSame(
                RbacCatalog::permissionScope($permission->key),
                $permission->scope,
            );
        }
    }

    public function test_ai_service_is_machine_only_with_no_permissions(): void
    {
        $this->assertTrue(RbacCatalog::isMachineRole(RoleKey::AI_SERVICE));

        $role = Role::query()->where('key', RoleKey::AI_SERVICE)->firstOrFail();

        $this->assertTrue($role->is_machine);
        $this->assertNull($role->panel);
        $this->assertSame(RbacCatalog::SCOPE_MACHINE, $role->scope);
        $this->assertSame(0, $role->permissions()->count());
        $this->assertSame([], RbacCatalog::permissionsFor(RoleKey::AI_SERVICE));
    }

    public function test_ai_service_is_never_map_able_to_a_human_panel(): void
    {
        $this->assertArrayNotHasKey(RoleKey::AI_SERVICE, PanelAccessPolicy::roleMap());
    }

    public function test_panel_policy_covers_every_human_role(): void
    {
        $humanRoles = collect(RoleKey::ALL)
            ->reject(fn (string $key) => $key === RoleKey::AI_SERVICE)
            ->sort()
            ->values()
            ->all();

        $mapped = collect(array_keys(PanelAccessPolicy::roleMap()))->sort()->values()->all();

        $this->assertSame($humanRoles, $mapped);
    }

    public function test_operator_cannot_self_approve_lba_reports(): void
    {
        $permissions = RbacCatalog::permissionsFor(RoleKey::LBA_OPERATOR);

        $this->assertNotContains(PermissionKey::REPORT_APPROVE, $permissions);
        $this->assertNotContains(PermissionKey::REPORT_PUBLISH, $permissions);
        $this->assertNotContains(PermissionKey::EXAM_REVIEW, $permissions);

        $role = Role::query()->where('key', RoleKey::LBA_OPERATOR)->firstOrFail();
        $this->assertFalse($role->permissions()->where('key', PermissionKey::REPORT_APPROVE)->exists());
    }

    public function test_reviewer_holds_the_approval_and_publish_permissions(): void
    {
        $permissions = RbacCatalog::permissionsFor(RoleKey::REVIEWER);

        $this->assertContains(PermissionKey::REPORT_APPROVE, $permissions);
        $this->assertContains(PermissionKey::REPORT_PUBLISH, $permissions);
        $this->assertContains(PermissionKey::EXAM_REVIEW, $permissions);
    }

    public function test_principal_roles_hold_no_clinical_permissions(): void
    {
        $clinical = RbacCatalog::clinicalPermissionKeys();

        foreach (RoleKey::PRINCIPAL_ROLES as $roleKey) {
            $overlap = array_intersect(RbacCatalog::permissionsFor($roleKey), $clinical);

            $this->assertSame([], $overlap, "Principal role [{$roleKey}] must hold no clinical permissions.");
        }
    }

    public function test_principal_roles_only_hold_platform_scoped_permissions(): void
    {
        foreach (RoleKey::PRINCIPAL_ROLES as $roleKey) {
            foreach (RbacCatalog::permissionsFor($roleKey) as $permissionKey) {
                $this->assertSame(
                    RbacCatalog::SCOPE_PLATFORM,
                    RbacCatalog::permissionScope($permissionKey),
                    "Principal role [{$roleKey}] holds non-platform permission [{$permissionKey}].",
                );
            }
        }
    }
}
