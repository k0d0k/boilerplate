<?php

namespace Tests\Feature\Platform\Onboarding;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Onboarding\ClinicSetupResult;
use App\Platform\Onboarding\ClinicSetupService;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseName;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 3 — one-click clinic setup orchestration.
 *
 * Proves the single setup procedure provisions + migrates the isolated tenant
 * database, registers central readiness, activates the permitted modules and
 * invites a Clinic Admin — and that it is IDEMPOTENT (a retry never duplicates a
 * database or loses data), CONCURRENCY-SAFE, audited and fails safely.
 */
class ClinicSetupServiceTest extends TestCase
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

    public function test_a_full_run_provisions_migrates_activates_and_invites(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();

        $result = $this->service()->setup($tenant, 'clinic.admin@example.test', 'Clinic Admin');

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->ready);
        $this->assertNotNull($result->activationUrl);

        $tenantId = (string) $tenant->getKey();

        // 1–2. The isolated database is provisioned, migrated and READY centrally.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenantId,
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        // 3. The permitted modules are installed + entitled for THIS tenant only.
        foreach (ClinicSetupService::DEFAULT_MODULES as $moduleKey) {
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

        // 4. The Clinic Admin is invited as an `invited` membership with tenant_admin.
        $admin = User::query()->where('email', 'clinic.admin@example.test')->firstOrFail();
        $this->assertTrue((bool) $admin->must_set_password);

        $membership = TenantMembership::query()
            ->where('user_id', $admin->getKey())
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->status);
        $this->assertTrue($membership->roles()->where('key', RoleKey::TENANT_ADMIN)->exists());
    }

    public function test_a_retry_is_idempotent_and_never_duplicates_a_database(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        $first = $this->service()->setup($tenant);
        $second = $this->service()->setup($tenant);

        $this->assertTrue($first->succeeded());
        $this->assertTrue($second->succeeded());

        $this->assertSame(1, TenantDatabase::query()->where('tenant_id', $tenantId)->count());

        foreach (ClinicSetupService::DEFAULT_MODULES as $moduleKey) {
            $this->assertSame(1, ModuleInstallation::query()
                ->where('module_key', $moduleKey)
                ->where('scope_key', $tenantId)
                ->count());
        }
    }

    public function test_the_run_holds_a_per_tenant_lock_and_refuses_a_concurrent_request(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        $lock = Cache::lock('clinic-setup:'.$tenantId, 60);
        $this->assertTrue($lock->get());

        try {
            $result = $this->service()->setup($tenant);

            $this->assertFalse($result->succeeded());
            $this->assertSame('concurrent_setup', $result->error);
            $this->assertNotNull($result->failedStep());
            $this->assertSame(ClinicSetupResult::STEP_CONCURRENCY, $result->failedStep()->key);

            // Nothing was written while another run held the lock.
            $this->assertDatabaseMissing('tenant_databases', ['tenant_id' => $tenantId]);
        } finally {
            $lock->release();
        }
    }

    public function test_provisioning_is_audited_with_the_clinic_activation_vocabulary(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        $this->service()->setup($tenant);

        // The provisioning operation itself is audited (redacted).
        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.tenancy',
            'action' => 'provision_database',
            'result' => 'allowed',
            'scope_key' => $tenantId,
        ]);

        // Module activation keeps the clinic-setup audit vocabulary.
        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'patients',
            'action' => 'activate',
            'result' => 'allowed',
            'scope_key' => $tenantId,
        ]);
    }

    public function test_a_long_migration_failure_is_audited_and_marks_readiness_not_ready(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        $this->app->bind(RunsTenantMigrations::class, fn () => new class implements RunsTenantMigrations
        {
            public function migrate(string $tenantId): void
            {
                throw new RuntimeException(str_repeat('x', 600));
            }
        });

        $result = app(ClinicSetupService::class)->setup($tenant);

        $this->assertFalse($result->succeeded());
        $this->assertFalse($result->ready);

        $database = $result->step(ClinicSetupResult::STEP_DATABASE);
        $this->assertNotNull($database);
        $this->assertTrue($database->isFailed());

        // Readiness fails closed; the refusal is audited.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenantId,
            'status' => TenantDatabase::STATUS_FAILED,
        ]);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.tenancy',
            'action' => 'provision_database',
            'result' => 'denied',
            'scope_key' => $tenantId,
        ]);

        $reason = ModuleAuditLog::query()
            ->where('module_key', 'platform.tenancy')
            ->where('action', 'provision_database')
            ->where('scope_key', $tenantId)
            ->sole()
            ->reason;

        $this->assertSame(str_repeat('x', 255), $reason);
    }

    private function service(): ClinicSetupService
    {
        return app(ClinicSetupService::class);
    }

    // ---------------------------------------- Database-only provisioning

    public function test_a_database_only_retry_converges_after_a_grant_failure(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        $provisioner = $this->failingOnceProvisioner();
        $this->app->instance(ProvisionsTenantDatabases::class, $provisioner);
        $this->app->instance(RunsTenantMigrations::class, new class implements RunsTenantMigrations
        {
            public function migrate(string $tenantId): void {}
        });

        // First attempt: the grant fails → readiness is not ready and the
        // registry records a NON-DESTRUCTIVE `failed` state.
        $first = $this->service()->provisionDatabase($tenant, null);

        $this->assertFalse($first->succeeded());
        $this->assertFalse($first->ready);
        $this->assertSame(ClinicSetupResult::STEP_DATABASE, $first->failedStep()?->key);
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenantId,
            'status' => TenantDatabase::STATUS_FAILED,
        ]);

        // Retry: the SAME tenant converges — no new database is created and the
        // missing step (grant + migration) is completed.
        $provisioner->failing = false;
        $second = $this->service()->provisionDatabase($tenant, null);

        $this->assertTrue($second->succeeded());
        $this->assertTrue($second->ready);
        $this->assertSame(2, $provisioner->calls);
        $this->assertSame(1, TenantDatabase::query()->where('tenant_id', $tenantId)->count());
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenantId,
            'status' => TenantDatabase::STATUS_MIGRATED,
            'database_name' => app(TenantDatabaseName::class)->for($tenantId),
        ]);
    }

    public function test_a_database_only_provision_refuses_a_concurrent_run(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        $lock = Cache::lock('clinic-setup:'.$tenantId, 60);
        $this->assertTrue($lock->get());

        try {
            $result = $this->service()->provisionDatabase($tenant, null);

            $this->assertFalse($result->succeeded());
            $this->assertSame('concurrent_setup', $result->error);
            $this->assertSame(ClinicSetupResult::STEP_CONCURRENCY, $result->failedStep()?->key);
            $this->assertDatabaseMissing('tenant_databases', ['tenant_id' => $tenantId]);
        } finally {
            $lock->release();
        }
    }

    public function test_provisioning_never_re_points_a_registered_tenant_at_a_different_database(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        // A pre-existing (e.g. legacy/failed) registration with a different
        // physical name than the currently effective prefix computes.
        TenantDatabase::query()->create([
            'tenant_id' => $tenantId,
            'database_name' => 'legacy_prefix_'.$tenantId,
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_FAILED,
        ]);

        $provisioner = $this->failingOnceProvisioner();
        $provisioner->failing = false;
        $this->app->instance(ProvisionsTenantDatabases::class, $provisioner);

        $result = $this->service()->provisionDatabase($tenant, null);

        $this->assertFalse($result->succeeded());
        $this->assertSame(0, $provisioner->calls);

        // The recorded name (and thus the database + its data) is preserved.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenantId,
            'database_name' => 'legacy_prefix_'.$tenantId,
        ]);
    }

    /**
     * A provisioner that fails its FIRST call (simulating a grant that fails
     * after the database was created) and succeeds thereafter, counting calls.
     */
    private function failingOnceProvisioner(): ProvisionsTenantDatabases
    {
        return new class implements ProvisionsTenantDatabases
        {
            public int $calls = 0;

            public bool $failing = true;

            public function provision(string $tenantId): void
            {
                $this->calls++;

                if ($this->failing) {
                    throw new RuntimeException('synthetic grant failure');
                }
            }

            public function drop(string $tenantId): void {}

            public function exists(string $tenantId): bool
            {
                return true;
            }
        };
    }
}
