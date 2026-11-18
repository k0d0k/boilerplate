<?php

namespace Tests\Feature\Filament;

use App\Filament\Clinic\Pages\SelectTenant;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\RoleKey;
use App\Platform\Access\TenantContextManager;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1B-2B — Clinic tenant context integration (STEP 4/STEP 5/STEP 6).
 *
 * Exercises the real server layer: the Clinic dashboard is gated by the verified
 * tenant context, the "Pilih Mitra" screen is the only route to an explicit
 * choice, and the topbar switcher is a CSRF-protected, server-verified switch.
 * Every outcome is asserted against the backend, never the rendered widget.
 *
 * The switch itself is a Livewire round-trip, so the flows below are driven over
 * HTTP exactly like a browser does: render the real panel page, then POST the
 * rendered snapshot back to Livewire's update endpoint with the requested method
 * call. Livewire's own `test()` harness strips the panel middleware on its
 * synthetic update request, so the tenant-context guard would not be replayed;
 * the real endpoint preserves it (and folds `abort(403)` into a 403 response).
 */
class ClinicTenantContextTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    private function clinicUser(): User
    {
        return User::factory()->role(RoleKey::TENANT_OWNER)->create();
    }

    private function context(): ManagesTenantContext
    {
        return app(ManagesTenantContext::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers — the real browser round-trip
    |--------------------------------------------------------------------------
    */

    /** Render an authenticated panel page and return its markup. */
    private function renderPage(string $path): string
    {
        return $this->get($path)->assertOk()->getContent();
    }

    /** Extract a component's `wire:snapshot` from rendered page markup. */
    private function componentSnapshot(string $html, string $component): string
    {
        if (! preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The rendered page did not expose any Livewire snapshot.');
        }

        foreach ($matches[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                return $snapshot;
            }
        }

        $this->fail("The rendered page did not expose a [{$component}] component.");
    }

    /**
     * Drive Livewire's real update endpoint the way the browser does: POST the
     * server-rendered snapshot with a single method call. The panel middleware
     * (including the tenant-context guard) is replayed, so an unauthorized
     * action returns a genuine HTTP 403.
     *
     * @param  list<mixed>  $params
     */
    private function callLivewire(string $html, string $component, string $method, array $params = []): TestResponse
    {
        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $this->componentSnapshot($html, $component),
                'calls' => [['method' => $method, 'params' => $params, 'path' => '']],
                'updates' => [],
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /** The re-rendered markup returned by a Livewire update response. */
    private function renderedHtml(TestResponse $response): string
    {
        return (string) $response->json('components.0.effects.html');
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 5 — the dashboard requires a verified context
    |--------------------------------------------------------------------------
    */

    public function test_the_clinic_dashboard_is_denied_without_an_active_membership(): void
    {
        $user = $this->clinicUser();

        $this->actingAs($user)->get('/clinic')->assertForbidden();
    }

    public function test_a_suspended_clinic_identity_is_denied_the_dashboard(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->suspended()->create();
        $this->giveMembership($user, Tenant::factory()->create(), [RoleKey::TENANT_OWNER]);

        $this->actingAs($user)->get('/clinic')->assertForbidden();
    }

    public function test_the_clinic_dashboard_renders_with_a_single_active_membership(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->create(['name' => 'Mitra Amanah']);
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->actingAs($user)
            ->get('/clinic')
            ->assertOk()
            ->assertSee('Dasbor Klinik')
            // The verified tenant identity is shown in the topbar chip.
            ->assertSee('Mitra Amanah');
    }

    /*
    |--------------------------------------------------------------------------
    | Explicit selection — "Pilih Mitra"
    |--------------------------------------------------------------------------
    */

    public function test_multiple_memberships_are_redirected_to_explicit_selection(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create());
        $this->giveMembership($user, Tenant::factory()->create());

        $this->actingAs($user)->get('/clinic')->assertRedirect('/clinic/pilih-mitra');
    }

    public function test_the_selection_screen_lists_only_the_authorized_memberships(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create(['name' => 'Mitra Alpha']));
        $this->giveMembership($user, Tenant::factory()->create(['name' => 'Mitra Beta']));

        $this->actingAs($user)
            ->get('/clinic/pilih-mitra')
            ->assertOk()
            ->assertSee('Mitra Alpha')
            ->assertSee('Mitra Beta');
    }

    public function test_the_selection_screen_is_denied_without_any_membership(): void
    {
        $user = $this->clinicUser();

        $this->actingAs($user)->get('/clinic/pilih-mitra')->assertForbidden();
    }

    public function test_selecting_an_authorized_tenant_resolves_the_verified_context(): void
    {
        $user = $this->clinicUser();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $this->giveMembership($user, $a);
        $this->giveMembership($user, $b);

        $this->actingAs($user);

        $response = $this->callLivewire(
            $this->renderPage('/clinic/pilih-mitra'),
            SelectTenant::class,
            'selectTenant',
            [$b->getKey()],
        );

        $response->assertOk();
        $this->assertSame('/clinic', $response->json('components.0.effects.redirect'));
        $this->assertSame($b->getKey(), $this->context()->currentTenantId($user));
    }

    public function test_the_selection_screen_rejects_a_foreign_tenant(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create());
        $this->giveMembership($user, Tenant::factory()->create());
        $foreign = Tenant::factory()->create();

        $this->actingAs($user);

        $this->callLivewire(
            $this->renderPage('/clinic/pilih-mitra'),
            SelectTenant::class,
            'selectTenant',
            [$foreign->getKey()],
        )->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 4 — the topbar switcher
    |--------------------------------------------------------------------------
    */

    public function test_the_switcher_switches_between_authorized_tenants(): void
    {
        $user = $this->clinicUser();
        $a = Tenant::factory()->create(['name' => 'Mitra Alpha']);
        $b = Tenant::factory()->create(['name' => 'Mitra Beta']);
        $this->giveMembership($user, $a);
        $this->giveMembership($user, $b);

        $this->actingAs($user);

        // Several memberships: no tenant is chosen arbitrarily, so the identity
        // must select explicitly before the dashboard opens.
        $this->callLivewire(
            $this->renderPage('/clinic/pilih-mitra'),
            SelectTenant::class,
            'selectTenant',
            [$a->getKey()],
        )->assertOk();
        $this->assertSame($a->getKey(), $this->context()->currentTenantId($user));

        $dashboard = $this->renderPage('/clinic');
        $this->assertStringContainsString('Mitra Alpha', $dashboard);

        // Opening the listbox reveals every authorized membership — nothing
        // else, and nothing invented.
        $opened = $this->callLivewire($dashboard, 'tenant-switcher', 'toggle');
        $opened->assertOk();
        $this->assertStringContainsString('Mitra Beta', $this->renderedHtml($opened));

        // The switch is authorized server-side, then the dashboard reloads in
        // the new context instead of morphing stale tenant state.
        $switch = $this->callLivewire($dashboard, 'tenant-switcher', 'switchTo', [$b->getKey()]);
        $switch->assertOk();
        $this->assertSame('/clinic', $switch->json('components.0.effects.redirect'));
        $this->assertSame($b->getKey(), $this->context()->currentTenantId($user));
    }

    public function test_the_switcher_rejects_an_unknown_tenant(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create());

        $this->actingAs($user);

        $this->callLivewire(
            $this->renderPage('/clinic'),
            'tenant-switcher',
            'switchTo',
            [(string) Str::ulid()],
        )->assertForbidden();
    }

    public function test_the_switcher_rejects_a_foreign_tenant(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create());
        $foreign = Tenant::factory()->create();

        $this->actingAs($user);

        $this->callLivewire(
            $this->renderPage('/clinic'),
            'tenant-switcher',
            'switchTo',
            [$foreign->getKey()],
        )->assertForbidden();
    }

    public function test_the_switcher_rejects_a_revoked_membership(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create());
        $revoked = Tenant::factory()->create();
        $this->giveMembership($user, $revoked, [], TenantMembership::STATUS_REVOKED);

        $this->actingAs($user);

        $this->callLivewire(
            $this->renderPage('/clinic'),
            'tenant-switcher',
            'switchTo',
            [$revoked->getKey()],
        )->assertForbidden();
    }

    public function test_the_switcher_rejects_a_suspended_tenant(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create());
        $suspended = Tenant::factory()->suspended()->create();
        $this->giveMembership($user, $suspended);

        $this->actingAs($user);

        $this->callLivewire(
            $this->renderPage('/clinic'),
            'tenant-switcher',
            'switchTo',
            [$suspended->getKey()],
        )->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Session lifecycle & authority boundaries
    |--------------------------------------------------------------------------
    */

    public function test_a_client_supplied_tenant_parameter_is_not_treated_as_authority(): void
    {
        $user = $this->clinicUser();
        $own = Tenant::factory()->create();
        $foreign = Tenant::factory()->create();
        $this->giveMembership($user, $own);

        $this->actingAs($user)
            ->get('/clinic?tenant='.$foreign->getKey())
            ->assertOk();

        // The context stays on the identity's own verified membership.
        $this->assertSame($own->getKey(), $this->context()->currentTenantId($user));
    }

    public function test_logout_clears_the_tenant_context(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->actingAs($user)->get('/clinic')->assertOk();
        $this->assertSame(
            $tenant->getKey(),
            app('session')->driver()->get(TenantContextManager::SESSION_KEY),
        );

        $this->post('/clinic/logout')->assertRedirect();

        $this->assertGuest();
        $this->assertNull(app('session')->driver()->get(TenantContextManager::SESSION_KEY));
    }

    public function test_a_principal_identity_never_inherits_clinic_tenant_context(): void
    {
        // A Principal governance identity keeps its own context: even holding a
        // tenant membership it cannot cross into Clinic operations, and its own
        // panel is unaffected.
        $principal = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();
        $this->giveMembership($principal, Tenant::factory()->create(), [RoleKey::TENANT_OWNER]);

        $this->actingAs($principal)->get('/clinic')->assertForbidden();
        $this->actingAs($principal)->get('/principal')->assertOk();
    }

    public function test_a_clinic_identity_cannot_reach_the_principal_governance_panel(): void
    {
        $user = $this->clinicUser();
        $this->giveMembership($user, Tenant::factory()->create(), [RoleKey::TENANT_OWNER]);

        $this->actingAs($user)->get('/principal')->assertForbidden();
    }

    public function test_the_patient_panel_and_unified_login_are_unchanged(): void
    {
        $this->get('/login')->assertOk();

        // Phase 8A: the Patient panel now enforces a server-side tenant gateway,
        // so a patient identity always carries an active clinic membership.
        $patient = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($patient, Tenant::factory()->create());
        $this->actingAs($patient)->get('/patient')->assertOk();
    }
}
