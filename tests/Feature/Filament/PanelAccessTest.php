<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\PanelAccessPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1A-2 negative/positive panel-isolation tests.
 *
 * UI hiding is never the control: these tests hit the real panel routes so the
 * server-side `canAccessPanel()` policy must decide the outcome.
 */
class PanelAccessTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    private const PANEL_PATHS = ['principal', 'clinic', 'patient'];

    public function test_exactly_three_intended_panels_are_registered(): void
    {
        $ids = array_keys(Filament::getPanels());
        sort($ids);

        $this->assertSame(['clinic', 'patient', 'principal'], $ids);

        $this->assertSame('principal', Filament::getPanel('principal')->getPath());
        $this->assertSame('clinic', Filament::getPanel('clinic')->getPath());
        $this->assertSame('patient', Filament::getPanel('patient')->getPath());
    }

    public function test_no_unintended_admin_panel_is_registered(): void
    {
        $this->assertArrayNotHasKey('admin', Filament::getPanels());
    }

    public function test_unauthenticated_users_are_redirected_to_panel_login(): void
    {
        foreach (self::PANEL_PATHS as $path) {
            $this->get('/'.$path)->assertRedirect('/'.$path.'/login');
        }
    }

    public function test_authorized_identity_can_reach_its_own_panel(): void
    {
        $principal = User::factory()->role('principal_super_admin')->create();
        $this->actingAs($principal)->get('/principal')->assertOk();

        $clinic = User::factory()->role('tenant_owner')->create();
        // Phase 1B-2B (STEP 5): the Clinic dashboard requires a verified active
        // tenant context; the role alone routes the identity but grants no
        // operational context, so give it one synthetic active membership.
        $this->giveMembership($clinic, Tenant::factory()->create());
        $this->actingAs($clinic)->get('/clinic')->assertOk();

        $patient = User::factory()->role('patient_customer')->create();
        // Phase 8A: the Patient gateway requires a verified clinic context; the
        // role alone routes the identity but grants no operational context, so
        // give it one synthetic active membership.
        $this->giveMembership($patient, Tenant::factory()->create());
        $this->actingAs($patient)->get('/patient')->assertOk();
    }

    public function test_principal_identity_cannot_reach_clinic_or_patient_panel(): void
    {
        $principal = User::factory()->role('principal_super_admin')->create();

        $this->actingAs($principal)->get('/clinic')->assertForbidden();
        $this->actingAs($principal)->get('/patient')->assertForbidden();
    }

    public function test_clinic_identity_cannot_reach_principal_panel(): void
    {
        $clinic = User::factory()->role('tenant_admin')->create();

        $this->actingAs($clinic)->get('/principal')->assertForbidden();
    }

    public function test_patient_identity_cannot_reach_privileged_panels(): void
    {
        $patient = User::factory()->role('patient_customer')->create();

        $this->actingAs($patient)->get('/principal')->assertForbidden();
        $this->actingAs($patient)->get('/clinic')->assertForbidden();
    }

    public function test_suspended_identity_is_denied_on_every_panel(): void
    {
        $user = User::factory()->role('principal_super_admin')->suspended()->create();

        foreach (self::PANEL_PATHS as $path) {
            $this->actingAs($user)->get('/'.$path)->assertForbidden();
        }
    }

    public function test_unknown_role_is_denied_on_every_panel(): void
    {
        $user = User::factory()->create(); // role = null

        foreach (self::PANEL_PATHS as $path) {
            $this->actingAs($user)->get('/'.$path)->assertForbidden();
        }
    }

    public function test_policy_denies_unmapped_role_and_unknown_values(): void
    {
        $this->assertFalse(PanelAccessPolicy::roleCanAccessPanel('principal_super_admin', 'clinic'));
        $this->assertTrue(PanelAccessPolicy::roleCanAccessPanel('principal_super_admin', 'principal'));
        $this->assertFalse(PanelAccessPolicy::roleCanAccessPanel('not_a_real_role', 'principal'));
        $this->assertFalse(PanelAccessPolicy::roleCanAccessPanel(null, 'patient'));
        $this->assertFalse(PanelAccessPolicy::roleCanAccessPanel('', 'patient'));
    }
}
