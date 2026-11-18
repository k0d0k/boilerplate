<?php

namespace Tests\Feature\Platform\Access;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PanelResolver;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1B-2A transition safety (STEP 4).
 *
 * The provisional `users.role` field still governs *panel* access exactly as in
 * Phase 1B-1; the new membership tables only govern *permission* authorization.
 * Membership must not become a second, conflicting route into a panel, and the
 * unified staff login must keep failing closed for identities it cannot route.
 */
class AccessTransitionTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_three_panels_remain_registered_with_unchanged_paths(): void
    {
        $panels = array_keys(Filament::getPanels());
        sort($panels);

        $this->assertSame(['clinic', 'patient', 'principal'], $panels);
        $this->assertSame('principal', Filament::getPanel('principal')->getPath());
        $this->assertSame('clinic', Filament::getPanel('clinic')->getPath());
        $this->assertSame('patient', Filament::getPanel('patient')->getPath());
    }

    public function test_unified_staff_login_is_still_reachable(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_clinic_role_without_active_membership_gets_no_operational_context(): void
    {
        // Phase 1B-2B (STEP 5): the provisional role still *routes* the identity
        // to the Clinic panel (see `test_panel_resolver_routes_a_legacy_staff_identity`)
        // but it no longer grants operational tenant context on its own — the
        // dashboard is denied until an active membership exists. The Principal
        // governance panel stays out of reach for a Clinic identity.
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->actingAs($user)->get('/clinic')->assertForbidden();
        $this->actingAs($user)->get('/principal')->assertForbidden();
    }

    public function test_clinic_role_with_active_membership_reaches_the_dashboard(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->actingAs($user)->get('/clinic')->assertOk();
    }

    public function test_membership_does_not_grant_panel_access(): void
    {
        // No provisional role: panel access must stay denied even though the user
        // holds a privileged tenant membership.
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->actingAs($user)->get('/clinic')->assertForbidden();
        $this->actingAs($user)->get('/principal')->assertForbidden();
        $this->actingAs($user)->get('/patient')->assertForbidden();
    }

    public function test_principal_assignment_does_not_grant_panel_access_on_its_own(): void
    {
        $user = User::factory()->create();
        $this->grantPrincipalRole($user, RoleKey::PRINCIPAL_SUPER_ADMIN);

        $this->actingAs($user)->get('/principal')->assertForbidden();
    }

    public function test_staff_login_resolver_fails_closed_for_membership_only_identity(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->assertNull(app(PanelResolver::class)->staffPanelPathFor($user));
    }

    public function test_panel_resolver_routes_a_legacy_staff_identity(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->assertSame(PanelAccessPolicy::PANEL_CLINIC, app(PanelResolver::class)->staffPanelPathFor($user));
    }
}
