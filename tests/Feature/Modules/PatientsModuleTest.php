<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 3 — Patients module (focused essentials).
 *
 * Exercises patient CRUD, the archive-not-delete contract, tenant isolation,
 * branch/role access rules, FeatureGate activation and basic page rendering.
 * Only synthetic identities and patient names are used.
 */
class PatientsModuleTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'patients';

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

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    /**
     * Bind the verified tenant database, as the request middleware would.
     * Tenant-owned models may only be used inside a configured tenant context.
     */
    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function activate(Tenant $tenant): void
    {
        RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    // -------------------------------------------------------------- CRUD

    public function test_a_patient_is_registered_with_a_generated_tenant_number(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Patient One',
            'registered_branch_id' => $branch->getKey(),
            'registered_at' => now(),
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $this->assertSame('P-000001', $patient->patient_number);
        $this->assertTrue($patient->isActive());
        $this->assertSame('Synthetic Patient One', $patient->full_name);

        $this->assertDatabaseHas('patients', ['id' => $patient->getKey()], 'tenant');
        $this->assertDatabaseHas('patient_audit_logs', [
            'patient_id' => $patient->getKey(),
            'action' => PatientAuditLog::ACTION_CREATED,
        ], 'tenant');
    }

    public function test_patients_are_archived_not_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Archive',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        app(PatientRegistrar::class)->archive($patient, $owner);

        $this->assertTrue($patient->fresh()->isArchived());
        $this->assertDatabaseHas('patients', ['id' => $patient->getKey()], 'tenant');
    }

    // ------------------------------------------------- Tenant isolation

    public function test_a_patient_of_another_tenant_is_never_visible(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);
        $this->bindTenant($tenantA);

        app(PatientRegistrar::class)->create([
            'full_name' => 'Tenant A Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $ownerA);

        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->tenantDatabases()->configure((string) $tenantB->getKey());

        // The connection was re-pointed at tenant B, so tenant A's patient is
        // invisible — and no rows leak in from the central database.
        $this->assertSame(0, Patient::query()->count());
    }

    // ------------------------------------------------- Access rules

    public function test_the_patients_resource_requires_an_active_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);

        // Module discovered but never activated: deny by default.
        $this->actingAs($owner)->get('/clinic/patients')->assertForbidden();
    }

    public function test_a_role_without_patient_permissions_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $finance = $this->member($tenant, RoleKey::TENANT_FINANCE);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->actingAs($finance)->get('/clinic/patients')->assertForbidden();
    }

    public function test_a_receptionist_with_manage_permission_reaches_the_patients_list(): void
    {
        $tenant = Tenant::factory()->create();
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->actingAs($receptionist)->get('/clinic/patients')->assertOk();
    }

    // ------------------------------------------------- Page rendering

    public function test_the_patients_list_and_create_pages_render(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Rendered',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $this->actingAs($owner)
            ->get('/clinic')
            ->assertOk()
            // The Clinic sidebar exposes a real, routed Patients link.
            ->assertSee('clinic/patients', false);

        $this->actingAs($owner)
            ->get('/clinic/patients')
            ->assertOk()
            ->assertSee('Synthetic Rendered');

        $this->actingAs($owner)->get('/clinic/patients/create')->assertOk();
    }
}
