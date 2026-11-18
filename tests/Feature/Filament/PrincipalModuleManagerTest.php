<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Pages\ModuleManager;
use App\Models\Tenant;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\EntitlementStatus;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleAdministration;
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
 * Phase 7D — Principal Module Manager (server layer).
 *
 * The manager only ever lists modules that are trusted (declared on disk) and
 * installed in the central catalog, and every enable/disable is re-derived
 * server side through {@see ModuleAdministration} —
 * which enforces the dependency checks and writes the audit trail the FeatureGate
 * itself relies on. UI visibility is never the authorization boundary.
 */
class PrincipalModuleManagerTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    private const MODULE = 'demo.showcase';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    // ------------------------------------------------------- Access control

    public function test_the_module_manager_boots_for_an_authorized_principal(): void
    {
        Tenant::factory()->create(['name' => 'Klinik Sejahtera']);

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN))
            ->get('/principal/modules')
            ->assertOk()
            ->assertSee('Klinik Sejahtera');
    }

    public function test_a_principal_without_the_module_permission_is_denied(): void
    {
        // principal_finance holds subscription/billing/analytics authority only.
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);

        $this->actingAs($finance)->get('/principal/modules')->assertForbidden();
        $this->assertFalse(ModuleManager::canAccess());
    }

    public function test_a_denied_principal_cannot_mutate_even_if_reached(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installedModule();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));

        $page = new ModuleManager;
        $page->tenantId = (string) $tenant->getKey();

        // The authorization boundary is re-derived server side, not from UI
        // visibility: reaching the action still fails closed.
        $this->assertThrows(
            fn () => $page->enable(self::MODULE),
            HttpException::class,
        );

        $this->assertDatabaseMissing('module_installations', [
            'module_key' => self::MODULE,
            'scope_key' => $tenant->getKey(),
        ]);
    }

    // ---------------------------------------------------------- Enable/disable

    public function test_an_authorized_principal_can_enable_a_module_for_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installedModule();
        $actor = $this->principal();

        $this->actingAs($actor);

        $page = new ModuleManager;
        $page->tenantId = (string) $tenant->getKey();
        $page->enable(self::MODULE);

        $installation = ModuleInstallation::query()
            ->where('module_key', self::MODULE)
            ->where('scope', ModuleScope::TENANT)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(ModuleStatus::ENABLED, $installation->status);
        $this->assertTrue($installation->isEnabled());

        $entitlement = ModuleEntitlement::query()
            ->where('module_key', self::MODULE)
            ->where('scope', ModuleScope::TENANT)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(EntitlementStatus::GRANTED, $entitlement->status);
        $this->assertTrue($entitlement->isGranted());

        // The mutation is audited against the acting principal.
        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => self::MODULE,
            'action' => 'enable',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope' => ModuleScope::TENANT,
            'scope_key' => $tenant->getKey(),
            'actor_id' => $actor->getKey(),
            'reason' => null,
        ]);
    }

    public function test_an_authorized_principal_can_disable_a_module_for_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installedModule();
        $actor = $this->principal();

        $this->actingAs($actor);

        $page = new ModuleManager;
        $page->tenantId = (string) $tenant->getKey();
        $page->enable(self::MODULE);
        $page->disable(self::MODULE);

        $installation = ModuleInstallation::query()
            ->where('module_key', self::MODULE)
            ->where('scope', ModuleScope::TENANT)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(ModuleStatus::DISABLED, $installation->status);
        $this->assertFalse($installation->isEnabled());

        $this->assertSame(
            EntitlementStatus::REVOKED,
            ModuleEntitlement::query()
                ->where('module_key', self::MODULE)
                ->where('scope_key', $tenant->getKey())
                ->firstOrFail()
                ->status,
        );

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => self::MODULE,
            'action' => 'disable',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope_key' => $tenant->getKey(),
        ]);
    }

    // -------------------------------------------------------- Denial + audit

    public function test_an_unknown_module_is_denied_and_audited_without_writing_state(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installedModule();
        $actor = $this->principal();

        $this->actingAs($actor);

        $page = new ModuleManager;
        $page->tenantId = (string) $tenant->getKey();
        $page->enable('ghost.module');

        $this->assertDatabaseMissing('module_installations', [
            'module_key' => 'ghost.module',
        ]);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'ghost.module',
            'action' => 'enable',
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'unknown_module',
            'scope_key' => $tenant->getKey(),
            'actor_id' => $actor->getKey(),
        ]);
    }

    // ------------------------------------------------------------------ View

    public function test_the_rows_surface_the_trusted_module_state(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installedModule();
        $this->actingAs($this->principal());

        $page = new ModuleManager;
        $page->tenantId = (string) $tenant->getKey();

        $rows = $page->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(self::MODULE, $rows[0]['key']);
        $this->assertFalse($rows[0]['enabled']);
        $this->assertFalse($rows[0]['entitled']);
        $this->assertNull($rows[0]['dependency']);
    }

    public function test_dependency_reason_labels_are_localized(): void
    {
        $this->actingAs($this->principal());

        $page = new ModuleManager;

        $this->assertSame(
            __('lba.modules.reason_unknown_module'),
            $page->reasonLabel('unknown_module'),
        );
    }

    private function installedModule(string $key = self::MODULE): RegisteredModule
    {
        return RegisteredModule::factory()->forKey($key)->enabled()->create();
    }
}
