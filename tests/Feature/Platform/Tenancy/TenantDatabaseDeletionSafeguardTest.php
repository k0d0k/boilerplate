<?php

namespace Tests\Feature\Platform\Tenancy;

use App\Models\Tenant;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Exceptions\TenantDatabaseDeletionDeniedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 2A safety patch — safeguards around the destructive `lba:tenant:drop`.
 *
 * Deletion is restricted to local/testing, cannot be forced in staging or
 * production, requires explicit identity confirmation, and fails closed for
 * missing, unknown or unregistered targets.
 */
class TenantDatabaseDeletionSafeguardTest extends TestCase
{
    use BuildsTenantDatabases, RefreshDatabase;

    private const CONFIRM = 'Confirm the tenant id to delete by typing it exactly';

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

    private function provisionedTenant(): Tenant
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('lba:tenant:provision', ['tenant' => $tenant->getKey()])->assertSuccessful();

        return $tenant;
    }

    /*
    |--------------------------------------------------------------------------
    | 1 & 2. Environment restriction
    |--------------------------------------------------------------------------
    */

    public function test_drop_is_denied_in_production_even_with_force(): void
    {
        $tenant = $this->provisionedTenant();

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('lba:tenant:drop', ['tenant' => $tenant->getKey(), '--force' => true])
            ->assertFailed();

        $this->assertFileExists($this->tenantDatabaseFile($tenant));
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => 'provisioned',
        ]);
    }

    public function test_drop_is_denied_in_staging(): void
    {
        $tenant = $this->provisionedTenant();

        $this->app->detectEnvironment(fn () => 'staging');

        $this->artisan('lba:tenant:drop', ['tenant' => $tenant->getKey(), '--force' => true])
            ->assertFailed();

        $this->assertFileExists($this->tenantDatabaseFile($tenant));
    }

    public function test_the_provisioner_refuses_physical_deletion_outside_local_and_testing(): void
    {
        $tenant = Tenant::factory()->create();

        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(TenantDatabaseDeletionDeniedException::class);

        app(ProvisionsTenantDatabases::class)->drop((string) $tenant->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Explicit identity confirmation
    |--------------------------------------------------------------------------
    */

    public function test_drop_aborts_when_the_identity_confirmation_does_not_match(): void
    {
        $tenant = $this->provisionedTenant();

        $this->artisan('lba:tenant:drop', ['tenant' => $tenant->getKey()])
            ->expectsQuestion(self::CONFIRM, 'wrong-tenant-id')
            ->assertFailed();

        $this->assertFileExists($this->tenantDatabaseFile($tenant));
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => 'provisioned',
        ]);
    }

    public function test_drop_aborts_on_an_empty_confirmation(): void
    {
        $tenant = $this->provisionedTenant();

        $this->artisan('lba:tenant:drop', ['tenant' => $tenant->getKey()])
            ->expectsQuestion(self::CONFIRM, '')
            ->assertFailed();

        $this->assertFileExists($this->tenantDatabaseFile($tenant));
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Fail closed on missing / unregistered targets
    |--------------------------------------------------------------------------
    */

    public function test_drop_fails_closed_for_an_unknown_tenant(): void
    {
        $this->artisan('lba:tenant:drop', ['tenant' => (string) Str::ulid()])
            ->assertFailed();
    }

    public function test_drop_fails_closed_when_the_tenant_has_no_registered_database(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();

        // Storage exists but was never registered centrally: refuse to delete it.
        app(ProvisionsTenantDatabases::class)->provision((string) $tenant->getKey());

        $this->artisan('lba:tenant:drop', ['tenant' => $tenant->getKey()])
            ->assertFailed();

        $this->assertFileExists($this->tenantDatabaseFile($tenant));
    }
}
