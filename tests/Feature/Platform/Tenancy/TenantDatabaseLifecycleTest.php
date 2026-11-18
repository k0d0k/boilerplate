<?php

namespace Tests\Feature\Platform\Tenancy;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 2A — tenant database lifecycle through controlled console commands.
 *
 * Provisioning, migration and removal are only reachable from the console
 * (never a browser request) and always record their outcome in the central
 * `tenant_databases` registry.
 */
class TenantDatabaseLifecycleTest extends TestCase
{
    use BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    public function test_provisioning_creates_the_database_and_registers_it_centrally(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('lba:tenant:provision', ['tenant' => $tenant->getKey()])
            ->assertSuccessful();

        $this->assertFileExists($this->tenantDatabaseFile($tenant));
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => 'provisioned',
        ]);
    }

    public function test_migrating_applies_the_tenant_schema(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('lba:tenant:provision', ['tenant' => $tenant->getKey()])->assertSuccessful();
        $this->artisan('lba:tenant:migrate', ['tenant' => $tenant->getKey()])->assertSuccessful();

        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => 'migrated',
        ]);

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue(Schema::connection('tenant')->hasTable('tenant_smoke_records'));
    }

    public function test_provisioning_can_migrate_in_one_step(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('lba:tenant:provision', ['tenant' => $tenant->getKey(), '--migrate' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => 'migrated',
        ]);
    }

    public function test_dropping_removes_the_database_and_marks_it_dropped(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('lba:tenant:provision', ['tenant' => $tenant->getKey()])->assertSuccessful();
        $this->artisan('lba:tenant:drop', ['tenant' => $tenant->getKey()])
            ->expectsQuestion('Confirm the tenant id to delete by typing it exactly', $tenant->getKey())
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->tenantDatabaseFile($tenant));
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => 'dropped',
        ]);
    }

    public function test_a_tenant_can_be_resolved_by_slug(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'mitra-alpha']);

        $this->artisan('lba:tenant:provision', ['tenant' => 'mitra-alpha'])->assertSuccessful();

        $this->assertFileExists($this->tenantDatabaseFile($tenant));
    }

    public function test_provisioning_an_unknown_tenant_fails(): void
    {
        $this->artisan('lba:tenant:provision', ['tenant' => (string) Str::ulid()])
            ->assertFailed();
    }
}
