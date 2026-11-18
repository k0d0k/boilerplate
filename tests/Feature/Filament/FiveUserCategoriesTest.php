<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Resources\TenantResource\RelationManagers\MembershipsRelationManager;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Access\UserCategory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS6.E — the five user categories, end to end.
 *
 * Each category keeps its existing panel routing and membership/tenant
 * isolation: the categories are a DISPLAY / ONBOARDING layer, so an identity in
 * one category still never reaches another panel, a suspended membership still
 * grants nothing, and the Clinic roster is scoped to the verified tenant only.
 */
class FiveUserCategoriesTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, DrivesPrincipalPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /** A Clinic identity in $tenant holding a single role. */
    private function clinicIdentity(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    public function test_each_category_lands_on_its_panel_and_is_rejected_from_others(): void
    {
        $tenant = Tenant::factory()->create();

        // 1. Super Admin Principal
        $principal = $this->principal(RoleKey::PRINCIPAL_SUPER_ADMIN);
        $this->actingAs($principal)->get('/principal')->assertOk();
        $this->actingAs($principal)->get('/clinic')->assertForbidden();
        $this->actingAs($principal)->get('/patient')->assertForbidden();

        // 2. Clinic Admin
        $clinicAdmin = $this->clinicIdentity($tenant, RoleKey::TENANT_ADMIN);
        $this->actingAs($clinicAdmin)->get('/clinic')->assertOk();
        $this->actingAs($clinicAdmin)->get('/principal')->assertForbidden();

        // 3. Doctor / Medical Staff
        $medical = $this->clinicIdentity($tenant, RoleKey::LBA_OPERATOR);
        $this->actingAs($medical)->get('/clinic')->assertOk();
        $this->actingAs($medical)->get('/principal')->assertForbidden();

        // 4. Staff
        $staff = $this->clinicIdentity($tenant, RoleKey::RECEPTIONIST);
        $this->actingAs($staff)->get('/clinic')->assertOk();
        $this->actingAs($staff)->get('/principal')->assertForbidden();

        // 5. Patient
        $patient = $this->clinicIdentity($tenant, RoleKey::PATIENT_CUSTOMER);
        $this->actingAs($patient)->get('/patient')->assertOk();
        $this->actingAs($patient)->get('/clinic')->assertForbidden();
        $this->actingAs($patient)->get('/principal')->assertForbidden();
    }

    /**
     * Functional access for EVERY human role behind the five categories: each
     * one belongs to a category, reaches that category's panel, and is rejected
     * from the other panels. This is the granular RBAC the categories group, so
     * the routing must hold for all 15 roles — not just one sample per category.
     */
    public function test_every_human_role_reaches_only_its_own_category_panel(): void
    {
        $tenant = Tenant::factory()->create();

        $paths = [
            'principal' => '/principal',
            'clinic' => '/clinic',
            'patient' => '/patient',
        ];

        $humanRoles = array_merge(
            RoleKey::PRINCIPAL_ROLES,
            RoleKey::TENANT_ROLES,
            [RoleKey::PATIENT_CUSTOMER],
        );

        foreach ($humanRoles as $roleKey) {
            $category = UserCategory::categoryForRole($roleKey);
            $this->assertNotNull($category, "Role [{$roleKey}] must belong to a user category.");

            $expectedPanel = UserCategory::panel($category);
            $this->assertNotNull($expectedPanel, "Category [{$category}] must map to a panel.");

            $user = $expectedPanel === 'principal'
                ? $this->principal($roleKey)
                : $this->clinicIdentity($tenant, $roleKey);

            foreach ($paths as $panel => $path) {
                $response = $this->actingAs($user)->get($path);

                if ($panel === $expectedPanel) {
                    $response->assertOk();
                } else {
                    $response->assertForbidden();
                }
            }
        }
    }

    public function test_a_suspended_membership_grants_no_clinic_context(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->role(RoleKey::TENANT_ADMIN)->create();

        $this->giveMembership($user, $tenant, [RoleKey::TENANT_ADMIN], 'suspended');

        // The role routes to the panel, but a suspended membership yields no
        // operational context, so Clinic operations are denied outright.
        $this->actingAs($user)->get('/clinic')->assertForbidden();
    }

    public function test_the_clinic_roster_shows_only_the_verified_tenants_staff(): void
    {
        $own = Tenant::factory()->create();
        $other = Tenant::factory()->create();

        $admin = $this->clinicIdentity($own, RoleKey::TENANT_ADMIN);

        $ownStaff = $this->clinicIdentity($own, RoleKey::RECEPTIONIST);
        $ownStaff->forceFill(['name' => 'Own Tenant Receptionist', 'email' => 'own@example.test'])->save();

        $otherStaff = $this->clinicIdentity($other, RoleKey::RECEPTIONIST);
        $otherStaff->forceFill(['name' => 'Other Tenant Receptionist', 'email' => 'other@example.test'])->save();

        $response = $this->actingAs($admin)->get('/clinic/pengguna-staf')->assertOk();

        $response->assertSee('Own Tenant Receptionist');
        $response->assertDontSee('Other Tenant Receptionist');

        // Every one of the five categories' clinic-side groups is labelled.
        $response->assertSee(UserCategory::label(UserCategory::CLINIC_ADMIN));
        $response->assertSee(UserCategory::label(UserCategory::MEDICAL_STAFF));
        $response->assertSee(UserCategory::label(UserCategory::STAFF));
    }

    public function test_an_ordinary_staff_member_cannot_open_the_clinic_roster(): void
    {
        $tenant = Tenant::factory()->create();

        // A receptionist has no `tenant.profile.manage` capability.
        $staff = $this->clinicIdentity($tenant, RoleKey::RECEPTIONIST);

        $this->actingAs($staff)->get('/clinic/pengguna-staf')->assertForbidden();
    }

    public function test_a_patient_and_a_principal_cannot_open_the_clinic_roster(): void
    {
        $tenant = Tenant::factory()->create();

        $patient = $this->clinicIdentity($tenant, RoleKey::PATIENT_CUSTOMER);
        $this->actingAs($patient)->get('/clinic/pengguna-staf')->assertForbidden();

        $principal = $this->principal();
        $this->actingAs($principal)->get('/clinic/pengguna-staf')->assertForbidden();
    }

    public function test_the_principal_staff_screen_shows_the_user_category(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $response = $this->callRelationManager($html, MembershipsRelationManager::class, []);

        $response->assertOk();
        $this->assertStringContainsString(
            __('lba.user_categories.column'),
            (string) $response->json('components.0.effects.html'),
        );
    }
}
