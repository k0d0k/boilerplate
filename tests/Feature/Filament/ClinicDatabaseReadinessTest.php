<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Tenancy\Models\TenantDatabase;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * WS4 Priority 0 — Clinic tenant database readiness gate.
 *
 * A Clinic identity with a verified tenant context must still be denied when the
 * tenant's isolated database is not verifiably migrated. The gate FAILS CLOSED:
 * a non-ready registry status (pending, provisioned-but-not-migrated, failed or
 * dropped) AND the ABSENCE of a registry row are both denied with HTTP 503 and a
 * useful message, so only a verified, migrated tenant database may reach
 * clinical modules.
 */
class ClinicDatabaseReadinessTest extends TestCase
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

    private function registerDatabase(Tenant $tenant, string $status): void
    {
        TenantDatabase::query()->create([
            'tenant_id' => $tenant->getKey(),
            'database_name' => 'tenant_'.$tenant->getKey(),
            'driver' => 'sqlite',
            'status' => $status,
        ]);
    }

    public function test_the_clinic_dashboard_is_unavailable_when_the_tenant_database_is_not_migrated(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->registerDatabase($tenant, TenantDatabase::STATUS_PROVISIONED);

        $this->actingAs($user)->get('/clinic')->assertStatus(503);
    }

    public function test_the_clinic_dashboard_is_unavailable_when_the_tenant_database_failed(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->registerDatabase($tenant, TenantDatabase::STATUS_FAILED);

        $this->actingAs($user)->get('/clinic')->assertStatus(503);
    }

    public function test_the_clinic_dashboard_renders_when_the_tenant_database_is_migrated(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create(['name' => 'Mitra Siap']);
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->registerDatabase($tenant, TenantDatabase::STATUS_MIGRATED);

        $this->actingAs($user)->get('/clinic')->assertOk()->assertSee('Mitra Siap');
    }

    public function test_the_clinic_dashboard_is_unavailable_without_a_database_registry_row(): void
    {
        // FAIL CLOSED: an unregistered tenant database is unverified, never
        // implicitly usable.
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->actingAs($user)->get('/clinic')->assertStatus(503);
    }
}
