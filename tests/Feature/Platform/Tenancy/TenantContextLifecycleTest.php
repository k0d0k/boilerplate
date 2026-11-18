<?php

namespace Tests\Feature\Platform\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\RoleKey;
use App\Platform\Tenancy\Http\Middleware\InitializeTenantDatabase;
use App\Platform\Tenancy\Jobs\Concerns\UsesTenantDatabase;
use App\Platform\Tenancy\Jobs\Middleware\TenantDatabaseContext;
use App\Platform\Tenancy\Models\TenantSmokeRecord;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 2A — request/Livewire/job lifecycle for the tenant database.
 *
 * Between any two units of work the tenant connection is fully released, so a
 * request, Livewire update or queued job can never inherit another tenant's
 * connection or data.
 */
class TenantContextLifecycleTest extends TestCase
{
    use BuildsAccess, BuildsTenantDatabases, RefreshDatabase;

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

    private function request(User $user): Request
    {
        $request = Request::create('/clinic');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /*
    |--------------------------------------------------------------------------
    | Request / Livewire middleware
    |--------------------------------------------------------------------------
    */

    public function test_the_middleware_binds_and_releases_the_verified_tenant_database(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->provisionTenantDatabase($tenant);

        // Single eligible membership: the context is auto-selected after
        // server-side verification.
        $this->assertSame($tenant->getKey(), app(ManagesTenantContext::class)->currentTenantId($user));

        $seen = null;
        app(InitializeTenantDatabase::class)->handle($this->request($user), function () use (&$seen) {
            $seen = $this->tenantDatabases()->current();

            return response('ok');
        });

        $this->assertSame($tenant->getKey(), $seen, 'The tenant database must be bound during the request.');
        $this->assertNull($this->tenantDatabases()->current(), 'The tenant connection must be released after the request.');
        $this->assertNull(config('database.connections.tenant.database'));
    }

    public function test_a_revoked_membership_binds_no_tenant_database(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER], TenantMembership::STATUS_REVOKED);
        $this->provisionTenantDatabase($tenant);

        $seen = 'unset';
        app(InitializeTenantDatabase::class)->handle($this->request($user), function () use (&$seen) {
            $seen = $this->tenantDatabases()->current();

            return response('ok');
        });

        $this->assertNull($seen);
    }

    public function test_a_suspended_tenant_binds_no_tenant_database(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $tenant = Tenant::factory()->suspended()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $seen = 'unset';
        app(InitializeTenantDatabase::class)->handle($this->request($user), function () use (&$seen) {
            $seen = $this->tenantDatabases()->current();

            return response('ok');
        });

        $this->assertNull($seen);
    }

    public function test_a_clinic_request_never_leaves_a_tenant_connection_open(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $tenant = Tenant::factory()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->provisionTenantDatabase($tenant);

        $this->actingAs($user)->get('/clinic')->assertOk();

        $this->assertNull($this->tenantDatabases()->current());
        $this->assertNull(config('database.connections.tenant.database'));
    }

    /*
    |--------------------------------------------------------------------------
    | Queue job middleware
    |--------------------------------------------------------------------------
    */

    public function test_a_job_binds_and_releases_its_tenant_database(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $this->provisionTenantDatabase($a);
        $this->provisionTenantDatabase($b);

        $job = new class
        {
            use UsesTenantDatabase;
        };
        $job->forTenant((string) $a->getKey());

        $middleware = new TenantDatabaseContext((string) $a->getKey());

        $middleware->handle($job, function () use ($a) {
            $this->assertSame($a->getKey(), $this->tenantDatabases()->current());
            TenantSmokeRecord::create(['label' => 'written by job A']);

            return 'ok';
        });

        // Released after the job: nothing leaks into the next unit of work.
        $this->assertNull($this->tenantDatabases()->current());

        $this->tenantDatabases()->configure($a->getKey());
        $this->assertSame(1, TenantSmokeRecord::query()->count());

        $this->tenantDatabases()->configure($b->getKey());
        $this->assertSame(0, TenantSmokeRecord::query()->count());
    }

    public function test_a_failing_job_still_releases_its_tenant_database(): void
    {
        $a = Tenant::factory()->create();
        $this->provisionTenantDatabase($a);

        $middleware = new TenantDatabaseContext((string) $a->getKey());

        try {
            $middleware->handle(new class {}, fn () => throw new RuntimeException('job failed'));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNull($this->tenantDatabases()->current());
    }
}
