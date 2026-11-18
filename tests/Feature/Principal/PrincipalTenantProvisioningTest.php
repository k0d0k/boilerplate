<?php

namespace Tests\Feature\Principal;

use App\Models\Tenant;
use App\Platform\Access\RoleKey;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseName;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 3 — Partner detail ("Kelola Mitra") database provisioning actions.
 *
 * Proves the Partner detail screen renders the four provisioning states
 * (Menunggu / Diproses / Siap / Gagal), exposes the "Siapkan Database" /
 * "Coba Lagi" action, that the action is authorized server-side, and that a
 * successful run converges the tenant to a READY, migrated registration.
 */
class PrincipalTenantProvisioningTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    public function test_the_partner_detail_shows_the_pending_state_and_the_setup_action(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();

        $this->actingAs($this->principal())
            ->get("/principal/master-tenants/{$tenant->id}/edit")
            ->assertOk()
            ->assertSee(__('principal.tenants.database_state_pending'))
            ->assertSee(__('principal.tenants.provision'));
    }

    public function test_provisioning_the_database_converges_the_tenant_to_ready(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();

        $this->actingAs($this->principal())
            ->post("/principal/master-tenants/{$tenant->id}/provision")
            ->assertOk()
            ->assertSee(__('principal.tenants.provision_result_ready'));

        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->id,
            'status' => TenantDatabase::STATUS_MIGRATED,
            'database_name' => app(TenantDatabaseName::class)->for((string) $tenant->getKey()),
        ]);

        // Ready: the state is shown and the action is withdrawn.
        $this->actingAs($this->principal())
            ->get("/principal/master-tenants/{$tenant->id}/edit")
            ->assertOk()
            ->assertSee(__('principal.tenants.database_state_ready'))
            ->assertDontSee(__('principal.tenants.provision'))
            ->assertDontSee(__('principal.tenants.provision_retry'));
    }

    public function test_a_failed_database_shows_the_safe_reason_and_the_retry_action(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();

        TenantDatabase::query()->create([
            'tenant_id' => $tenant->id,
            'database_name' => app(TenantDatabaseName::class)->for((string) $tenant->getKey()),
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_FAILED,
        ]);

        $this->actingAs($this->principal())
            ->get("/principal/master-tenants/{$tenant->id}/edit")
            ->assertOk()
            ->assertSee(__('principal.tenants.database_state_failed'))
            ->assertSee(__('principal.tenants.provision_retry'))
            ->assertSee(__('principal.tenants.provision_reason_database_error'));
    }

    public function test_the_provision_action_is_forbidden_without_tenant_authority(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))
            ->post("/principal/master-tenants/{$tenant->id}/provision")
            ->assertForbidden();

        $this->assertDatabaseMissing('tenant_databases', ['tenant_id' => $tenant->id]);
    }
}
