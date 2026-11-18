<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 3 — trusted clinic module registration + activation (STEP 5).
 *
 * Proves the explicit `lba:clinic:setup` procedure writes the real
 * installation + entitlement records the FeatureGate honours, so an activated
 * partner can reach the shipped Clinic resources.
 */
class ClinicModuleActivationTest extends TestCase
{
    use BuildsAccess, BuildsTenantDatabases, RefreshDatabase;

    /** @var list<string> */
    private const MODULES = ['patients', 'appointments'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    public function test_the_setup_command_registers_the_catalog_and_activates_both_modules(): void
    {
        $this->artisan('lba:clinic:setup', ['--fixture' => true])->assertSuccessful();

        $tenant = Tenant::query()->latest('created_at')->firstOrFail();
        $tenantId = (string) $tenant->getKey();

        foreach (self::MODULES as $moduleKey) {
            // The platform catalog is metadata only.
            $this->assertDatabaseHas('module_registry', ['module_key' => $moduleKey]);

            // The per-tenant installation + entitlement is what the gate honours.
            $this->assertDatabaseHas('module_installations', [
                'module_key' => $moduleKey,
                'scope' => ModuleScope::TENANT,
                'scope_key' => $tenantId,
                'status' => 'enabled',
            ]);

            $this->assertDatabaseHas('module_entitlements', [
                'module_key' => $moduleKey,
                'scope' => ModuleScope::TENANT,
                'scope_key' => $tenantId,
                'status' => 'granted',
            ]);
        }
    }

    public function test_an_activated_partner_owner_reaches_the_shipped_clinic_resources(): void
    {
        $this->artisan('lba:clinic:setup', ['--fixture' => true])->assertSuccessful();

        $tenant = Tenant::query()->latest('created_at')->firstOrFail();
        $membership = TenantMembership::query()
            ->where('tenant_id', (string) $tenant->getKey())
            ->firstOrFail();
        $owner = User::query()->findOrFail($membership->user_id);

        $this->assertTrue($membership->status === TenantMembership::STATUS_ACTIVE);

        $this->actingAs($owner)->get('/clinic/appointments')->assertOk();
        $this->actingAs($owner)->get('/clinic/patients')->assertOk();
    }
}
