<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Pages\TrustedModules;
use App\Models\Tenant;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleAdministration;
use App\Platform\ModuleRegistry\ModuleHealth;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\ModuleRegistry\ModuleStatus;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * WS4.6 — Principal Trusted Module / Plugin Registry (server layer).
 *
 * The registry only ever lists modules TRUSTED on disk (declared with an `lba`
 * manifest) and merges their catalog master-switch state. Every global
 * enable/disable is re-derived server side through {@see ModuleAdministration},
 * which enforces the platform-scope dependency checks, writes the audit trail
 * the FeatureGate relies on, and never grants anything to a tenant implicitly.
 */
class PrincipalTrustedModulesTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    private const MODULE = 'demo.showcase';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    // ------------------------------------------------------- Access control

    public function test_the_trusted_registry_page_boots_for_an_authorized_principal(): void
    {
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN))
            ->get('/principal/trusted-modules')
            ->assertOk()
            ->assertSee('Modul Terpercaya');
    }

    public function test_a_principal_without_the_module_permission_is_denied(): void
    {
        // principal_finance holds subscription/billing/analytics authority only.
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))
            ->get('/principal/trusted-modules')
            ->assertForbidden();

        $this->assertFalse(TrustedModules::canAccess());
    }

    public function test_a_denied_principal_cannot_mutate_even_if_reached(): void
    {
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));

        $page = new TrustedModules;

        // The authorization boundary is re-derived server side, not from UI
        // visibility: reaching the action still fails closed.
        $this->assertThrows(
            fn () => $page->enableGlobally(self::MODULE),
            HttpException::class,
        );

        $this->assertDatabaseMissing('module_registry', ['module_key' => self::MODULE]);
    }

    // ------------------------------------------------------------------ View

    public function test_the_rows_surface_the_trusted_contract_and_global_state(): void
    {
        $this->actingAs($this->principal());

        $rows = (new TrustedModules)->rows();

        $this->assertNotEmpty($rows);

        $row = collect($rows)->firstWhere('key', self::MODULE);

        $this->assertNotNull($row, 'The declared module must appear in the trusted registry.');
        $this->assertSame('demo.showcase', $row['key']);
        $this->assertTrue($row['compatible']);
        $this->assertSame(['tenant'], $row['scopes']);
        $this->assertSame(['id', 'ms-MY', 'en'], $row['locales']);
        $this->assertSame('entitlement', $row['entitlement']);
        $this->assertNull($row['dependency']);
        $this->assertFalse($row['installed']);
        $this->assertFalse($row['enabled']);
    }

    // ---------------------------------------------------------- Global state

    public function test_an_authorized_principal_can_enable_a_module_globally(): void
    {
        $actor = $this->principal();
        $this->actingAs($actor);

        (new TrustedModules)->enableGlobally(self::MODULE);

        $catalog = RegisteredModule::query()->where('module_key', self::MODULE)->firstOrFail();

        $this->assertTrue($catalog->is_installed);
        $this->assertSame(ModuleStatus::ENABLED, $catalog->status);
        $this->assertSame(ModuleHealth::HEALTHY, $catalog->health);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => self::MODULE,
            'action' => 'enable_global',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => '',
            'actor_id' => $actor->getKey(),
            'reason' => null,
        ]);
    }

    public function test_global_enable_never_grants_anything_to_a_tenant(): void
    {
        Tenant::factory()->create();
        $this->actingAs($this->principal());

        (new TrustedModules)->enableGlobally(self::MODULE);

        // The platform master switch is not a tenant grant: per-tenant
        // installation and entitlement stay required (tenant isolation intact).
        $this->assertDatabaseCount('module_installations', 0);
        $this->assertDatabaseCount('module_entitlements', 0);
    }

    public function test_an_authorized_principal_can_disable_a_module_globally_without_touching_tenants(): void
    {
        $this->actingAs($this->principal());

        app(ModuleAdministration::class)->enableGlobally(self::MODULE);
        (new TrustedModules)->disableGlobally(self::MODULE);

        $catalog = RegisteredModule::query()->where('module_key', self::MODULE)->firstOrFail();

        // The catalog row is kept (installed) but disabled — no clinical,
        // financial or tenant record is ever deleted.
        $this->assertTrue($catalog->is_installed);
        $this->assertSame(ModuleStatus::DISABLED, $catalog->status);

        $this->assertDatabaseCount('module_installations', 0);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => self::MODULE,
            'action' => 'disable_global',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope' => ModuleScope::PLATFORM,
        ]);
    }

    // -------------------------------------------------------- Denial + audit

    public function test_an_unknown_module_cannot_be_trusted_and_is_audited_denied(): void
    {
        $actor = $this->principal();
        $this->actingAs($actor);

        $trusted = app(ModuleAdministration::class)->enableGlobally('ghost.module', $actor);

        $this->assertFalse($trusted);
        $this->assertDatabaseMissing('module_registry', ['module_key' => 'ghost.module']);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'ghost.module',
            'action' => 'enable_global',
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'unknown_module',
            'scope' => ModuleScope::PLATFORM,
            'actor_id' => $actor->getKey(),
        ]);
    }

    public function test_platform_dependency_failure_reports_an_unknown_module(): void
    {
        $this->assertSame(
            'unknown_module',
            app(ModuleAdministration::class)->platformDependencyFailure('ghost.module'),
        );
    }
}
