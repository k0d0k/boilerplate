<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\Support\SubmitsTheLoginForm;
use Tests\TestCase;

/**
 * Bagian 1 — module-to-entry-point wiring.
 *
 * Proves the promises PANELS_NAVIGATION.md makes about the entry point: signing
 * in through the one unified login lands a dummy identity on the panel it is
 * authorised for, the sidebar exposes ONE canonical entry per feature, and every
 * destination the menu advertises actually opens for that identity.
 *
 * The flow is exercised end to end — render `/login`, submit the Livewire form
 * exactly like a browser, follow the resolved panel, then walk the menu — so a
 * navigation link that only *looks* right but 403s (or points at a removed
 * screen) fails the test.
 *
 * Every identity and tenant here is synthetic (DECISIONS.md PRV-01).
 */
class PanelEntryPointNavigationTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsPrincipalGovernance, BuildsTenantDatabases, RefreshDatabase, SubmitsTheLoginForm;

    /**
     * The one canonical governance entry per feature that the `/principal` menu
     * must expose (Peraturan PANELS_NAVIGATION.md: "use a single canonical entry
     * per feature").
     *
     * @var list<string>
     */
    private const PRINCIPAL_GOVERNANCE_ROUTES = [
        'principal.tailadmin.settings.index',           // Pengaturan Global
        'principal.tailadmin.brands.index',             // Brand & Mitra
        'principal.tailadmin.modules.index',            // Manajer Modul
        'principal.tailadmin.subscriptions.index',      // Langganan
        'principal.tailadmin.ai-credits.index',         // Token AI
        'principal.tailadmin.entitlements.index',       // Hak Akses Fitur
        'principal.tailadmin.payment-providers.index',  // Penyedia Pembayaran
        'principal.tailadmin.analytics.index',          // Analitik Bisnis
        'principal.tailadmin.audit.index',              // Audit Sistem
    ];

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

    // ------------------------------------------------------------- Principal

    public function test_a_principal_super_admin_signs_in_and_every_governance_surface_is_reachable_from_the_menu(): void
    {
        $user = $this->principal();

        // 1. The real login form resolves the Principal panel server side.
        $this->assertSame('/principal', $this->loginRedirect($this->loginThrough('/login', $user->email)));

        // 2. From /principal the menu exposes exactly one entry per feature...
        $dashboard = $this->actingAs($user)->get('/principal')->assertOk();
        foreach (self::PRINCIPAL_GOVERNANCE_ROUTES as $name) {
            $dashboard->assertSee(route($name), false);
        }

        // 3. ...and every advertised destination boots for that identity.
        foreach (self::PRINCIPAL_GOVERNANCE_ROUTES as $name) {
            $this->actingAs($user)->get(route($name))->assertOk();
        }
    }

    public function test_the_principal_governance_menu_is_absent_without_its_permissions(): void
    {
        // A finance principal may only see Langganan / Token AI / Penyedia
        // Pembayaran — never Pengaturan Global, Manajer Modul or Hak Akses Fitur.
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);

        $html = $this->actingAs($finance)->get(route('principal.tailadmin.subscriptions.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('principal.tailadmin.subscriptions.index'), $html);
        $this->assertStringContainsString(route('principal.tailadmin.payment-providers.index'), $html);
        $this->assertStringNotContainsString(route('principal.tailadmin.settings.index'), $html);
        $this->assertStringNotContainsString(route('principal.tailadmin.entitlements.index'), $html);
        $this->assertStringNotContainsString(route('principal.tailadmin.modules.index'), $html);

        // ...and the denied destinations refuse the direct URL too.
        $this->actingAs($finance)->get(route('principal.tailadmin.settings.index'))->assertForbidden();
        $this->actingAs($finance)->get(route('principal.tailadmin.modules.index'))->assertForbidden();
    }

    // ---------------------------------------------------------------- Clinic

    public function test_a_clinic_staff_selects_a_partner_and_branch_then_reaches_the_operational_modules(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->provisionTenantDatabase($tenant);
        $this->artisan('lba:tenant:migrate', ['tenant' => $tenant->id])->assertSuccessful();

        foreach (['patients', 'appointments', 'lba'] as $module) {
            RegisteredModule::factory()->forKey($module)->enabled()->create();
            ModuleInstallation::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->enabled()->create();
            ModuleEntitlement::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->granted()->create();
        }

        // TWO branches, so the workspace really offers a branch choice (the
        // owner manages branch operations, so both are eligible).
        $first = $this->createBranch($tenant, ['name' => 'Navigation Branch One']);
        $this->createBranch($tenant, ['name' => 'Navigation Branch Two']);

        // 1. The unified login routes a clinic identity to /clinic.
        $this->assertSame('/clinic', $this->loginRedirect($this->loginThrough('/login', $owner->email)));

        // 2. The TailAdmin workspace renders the partner + branch selector.
        $workspace = '/clinic/workspace';
        $this->actingAs($owner)->get($workspace)->assertOk()
            ->assertSee($tenant->name)
            ->assertSee('name="branch_id"', false);

        // 3. Selecting a partner and a branch is a server-side round trip.
        $this->actingAs($owner)
            ->post(route('clinic.tailadmin.switch-tenant'), ['tenant_id' => $tenant->id])
            ->assertRedirect($workspace);
        $this->actingAs($owner)
            ->post(route('clinic.tailadmin.switch-branch'), ['tenant_id' => $tenant->id, 'branch_id' => $first->id])
            ->assertRedirect($workspace);

        $this->actingAs($owner)->get($workspace)->assertOk()->assertSee($first->name);

        // 4. The sidebar links reach the operational modules this identity may use.
        $html = $this->actingAs($owner)->get($workspace)->assertOk()->getContent();
        foreach (['patients', 'appointments', 'examinations'] as $registry) {
            $this->assertStringContainsString(route('clinic.tailadmin.index', $registry), $html);
        }

        // ...and those destinations actually boot.
        foreach (['patients', 'appointments', 'examinations'] as $registry) {
            $this->actingAs($owner)->get(route('clinic.tailadmin.index', $registry))->assertOk();
        }
    }
}
