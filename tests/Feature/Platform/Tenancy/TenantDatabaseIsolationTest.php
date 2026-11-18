<?php

namespace Tests\Feature\Platform\Tenancy;

use App\Models\Tenant;
use App\Platform\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Platform\Tenancy\Exceptions\TenantContextMissingException;
use App\Platform\Tenancy\Models\TenantSmokeRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 2A — central/tenant isolation and connection resolution.
 *
 * Proves the core invariant: one central database, one isolated database per
 * tenant, and no path by which one tenant can read or write another tenant's
 * records. Uses separate temporary SQLite files because no MySQL server is
 * available in the sandbox.
 */
class TenantDatabaseIsolationTest extends TestCase
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

    private function twoProvisionedTenants(): array
    {
        $a = Tenant::factory()->create(['name' => 'Tenant A']);
        $b = Tenant::factory()->create(['name' => 'Tenant B']);

        $this->provisionTenantDatabase($a);
        $this->provisionTenantDatabase($b);

        return [$a, $b];
    }

    /*
    |--------------------------------------------------------------------------
    | Central database stays isolated from tenant clinical databases
    |--------------------------------------------------------------------------
    */

    public function test_the_central_database_contains_no_tenant_tables(): void
    {
        $central = (string) config('tenancy.central_connection');

        $this->assertTrue(Schema::connection($central)->hasTable('tenants'));
        $this->assertFalse(Schema::connection($central)->hasTable('tenant_smoke_records'));
    }

    public function test_a_tenant_database_contains_no_central_tables(): void
    {
        [$a] = $this->twoProvisionedTenants();

        $this->tenantDatabases()->configure($a->getKey());

        $this->assertTrue(Schema::connection('tenant')->hasTable('tenant_smoke_records'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('tenants'));
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant A cannot read/write Tenant B records
    |--------------------------------------------------------------------------
    */

    public function test_a_tenant_cannot_read_another_tenants_records(): void
    {
        [$a, $b] = $this->twoProvisionedTenants();

        $this->tenantDatabases()->configure($a->getKey());
        TenantSmokeRecord::create(['label' => 'A record']);

        $this->tenantDatabases()->configure($b->getKey());
        $this->assertSame(0, TenantSmokeRecord::query()->count());

        TenantSmokeRecord::create(['label' => 'B record']);
        $this->assertSame(['B record'], TenantSmokeRecord::query()->pluck('label')->all());

        $this->tenantDatabases()->configure($a->getKey());
        $this->assertSame(['A record'], TenantSmokeRecord::query()->pluck('label')->all());
    }

    public function test_a_tenant_write_never_appears_in_another_tenant(): void
    {
        [$a, $b] = $this->twoProvisionedTenants();

        $this->tenantDatabases()->configure($b->getKey());
        TenantSmokeRecord::create(['label' => 'only-in-b']);

        $this->tenantDatabases()->configure($a->getKey());
        $this->assertDatabaseMissing('tenant_smoke_records', ['label' => 'only-in-b'], 'tenant');
    }

    /*
    |--------------------------------------------------------------------------
    | Switching does not leak connections or data
    |--------------------------------------------------------------------------
    */

    public function test_switching_tenants_rebinds_the_connection_to_the_new_database(): void
    {
        [$a, $b] = $this->twoProvisionedTenants();

        $this->tenantDatabases()->configure($a->getKey());
        $this->assertSame($this->tenantDatabaseFile($a), DB::connection('tenant')->getDatabaseName());

        $this->tenantDatabases()->configure($b->getKey());
        $this->assertSame($this->tenantDatabaseFile($b), DB::connection('tenant')->getDatabaseName());
        $this->assertSame($b->getKey(), $this->tenantDatabases()->current());
    }

    public function test_forgetting_closes_the_connection_and_clears_the_target(): void
    {
        [$a] = $this->twoProvisionedTenants();

        $this->tenantDatabases()->configure($a->getKey());
        $this->assertSame($a->getKey(), $this->tenantDatabases()->current());

        $this->tenantDatabases()->forget();

        $this->assertNull($this->tenantDatabases()->current());
        $this->assertNull(config('database.connections.tenant.database'));
    }

    public function test_several_branches_of_one_tenant_share_the_tenant_database(): void
    {
        [$a, $b] = $this->twoProvisionedTenants();

        $this->tenantDatabases()->configure($a->getKey());
        TenantSmokeRecord::create(['branch_id' => 'branch-1', 'label' => 'front desk']);
        TenantSmokeRecord::create(['branch_id' => 'branch-2', 'label' => 'back office']);

        // Both branches live in the SAME tenant database.
        $this->assertSame(2, TenantSmokeRecord::query()->count());

        // ...and never in another tenant's database.
        $this->tenantDatabases()->configure($b->getKey());
        $this->assertSame(0, TenantSmokeRecord::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Fail closed
    |--------------------------------------------------------------------------
    */

    public function test_tenant_models_fail_closed_without_a_tenant_context(): void
    {
        $this->tenantDatabases()->forget();

        $this->expectException(TenantContextMissingException::class);

        TenantSmokeRecord::query()->count();
    }

    public function test_an_unknown_tenant_never_resolves_a_connection(): void
    {
        $this->expectException(TenantAccessDeniedException::class);

        $this->tenantDatabases()->configure((string) Str::ulid());
    }

    public function test_a_suspended_tenant_never_resolves_a_connection(): void
    {
        $suspended = Tenant::factory()->suspended()->create();

        $this->expectException(TenantAccessDeniedException::class);

        $this->tenantDatabases()->configure((string) $suspended->getKey());
    }

    public function test_a_malformed_tenant_id_never_resolves_a_connection(): void
    {
        $this->expectException(TenantAccessDeniedException::class);

        $this->tenantDatabases()->configure('not-a-tenant-ulid');
    }
}
