<?php

namespace Tests\Feature\Platform\Access;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use App\Platform\Access\UserCategory;
use Tests\TestCase;

/**
 * WS6.E — the five user categories.
 *
 * The catalog is a DISPLAY / ONBOARDING layer over the existing granular RBAC.
 * These tests pin the properties that keep it safe: exactly five categories,
 * every human role mapped to exactly one of them, each category routed to the
 * panel its roles are already allowed on, and no machine role ever categorized.
 */
class UserCategoryTest extends TestCase
{
    public function test_the_catalog_defines_exactly_five_categories(): void
    {
        $this->assertSame([
            UserCategory::SUPER_ADMIN_PRINCIPAL,
            UserCategory::CLINIC_ADMIN,
            UserCategory::MEDICAL_STAFF,
            UserCategory::STAFF,
            UserCategory::PATIENT,
        ], UserCategory::all());
    }

    public function test_every_human_role_maps_to_exactly_one_category(): void
    {
        foreach (RoleKey::ALL as $roleKey) {
            if ($roleKey === RoleKey::AI_SERVICE) {
                continue;
            }

            $category = UserCategory::categoryForRole($roleKey);

            $this->assertNotNull($category, "Role [{$roleKey}] has no user category.");
            $this->assertContains($category, UserCategory::ALL);
        }
    }

    public function test_the_machine_role_is_never_categorized(): void
    {
        $this->assertNull(UserCategory::categoryForRole(RoleKey::AI_SERVICE));
        $this->assertNull(UserCategory::categoryForRole(null));
        $this->assertNull(UserCategory::categoryForRole('not_a_real_role'));
    }

    public function test_each_category_groups_the_expected_roles(): void
    {
        $this->assertSame([
            RoleKey::PRINCIPAL_SUPER_ADMIN,
            RoleKey::PRINCIPAL_MODULE_ADMIN,
            RoleKey::PRINCIPAL_FINANCE,
            RoleKey::BRAND_MANAGER,
        ], UserCategory::roleKeys(UserCategory::SUPER_ADMIN_PRINCIPAL));

        $this->assertSame([
            RoleKey::TENANT_OWNER,
            RoleKey::TENANT_ADMIN,
        ], UserCategory::roleKeys(UserCategory::CLINIC_ADMIN));

        $this->assertSame([
            RoleKey::CONSULTANT,
            RoleKey::LBA_OPERATOR,
            RoleKey::REVIEWER,
        ], UserCategory::roleKeys(UserCategory::MEDICAL_STAFF));

        $this->assertSame([
            RoleKey::BRANCH_MANAGER,
            RoleKey::RECEPTIONIST,
            RoleKey::SCHEDULER,
            RoleKey::LOCATION_EVENT_COORDINATOR,
            RoleKey::COMMERCE_MANAGER,
            RoleKey::TENANT_FINANCE,
        ], UserCategory::roleKeys(UserCategory::STAFF));

        $this->assertSame([
            RoleKey::PATIENT_CUSTOMER,
        ], UserCategory::roleKeys(UserCategory::PATIENT));
    }

    public function test_every_categorized_role_still_routes_to_its_category_panel(): void
    {
        foreach (UserCategory::ALL as $category) {
            $panel = UserCategory::panel($category);

            $this->assertNotNull($panel);

            foreach (UserCategory::roleKeys($category) as $roleKey) {
                $this->assertTrue(
                    PanelAccessPolicy::roleCanAccessPanel($roleKey, $panel),
                    "Role [{$roleKey}] must route to the [{$panel}] panel.",
                );
            }
        }
    }

    public function test_categories_for_roles_is_ordered_and_deduplicated(): void
    {
        $categories = UserCategory::categoriesForRoles([
            RoleKey::REVIEWER,        // medical staff
            RoleKey::RECEPTIONIST,    // staff
            RoleKey::LBA_OPERATOR,    // medical staff (duplicate category)
        ]);

        $this->assertSame([
            UserCategory::MEDICAL_STAFF,
            UserCategory::STAFF,
        ], $categories);
    }

    public function test_grouped_role_options_are_grouped_and_name_each_permission(): void
    {
        $groups = UserCategory::groupedRoleOptions(RoleKey::TENANT_ROLES);

        // Clinic-relevant categories only — never the principal or patient group.
        $this->assertSame([
            UserCategory::label(UserCategory::CLINIC_ADMIN),
            UserCategory::label(UserCategory::MEDICAL_STAFF),
            UserCategory::label(UserCategory::STAFF),
        ], array_keys($groups));

        $medical = $groups[UserCategory::label(UserCategory::MEDICAL_STAFF)];

        $this->assertArrayHasKey(RoleKey::REVIEWER, $medical);

        // The option names the subrole AND its granular permissions.
        $this->assertStringContainsString(__('lba.roles.reviewer'), $medical[RoleKey::REVIEWER]);

        foreach (RbacCatalog::permissionsFor(RoleKey::REVIEWER) as $permission) {
            $this->assertStringContainsString(
                __("lba.permissions.{$permission}"),
                $medical[RoleKey::REVIEWER],
            );
        }
    }

    public function test_staff_invitation_groups_never_include_the_patient_category(): void
    {
        $groups = UserCategory::staffInvitationGroups();

        $this->assertArrayNotHasKey(UserCategory::label(UserCategory::PATIENT), $groups);
        $this->assertArrayNotHasKey(UserCategory::label(UserCategory::SUPER_ADMIN_PRINCIPAL), $groups);
    }
}
