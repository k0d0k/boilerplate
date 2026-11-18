<?php

namespace Tests\Feature\Platform\Tenancy;

use App\Models\Tenant;
use App\Platform\Onboarding\ClinicSetupService;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\OverrideAwareTenantDatabaseProvisioner;
use App\Platform\Tenancy\TenantDatabaseConflict;
use App\Platform\Tenancy\TenantDatabaseName;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Staging: ONE tenant on a manually-created MySQL database via `.env`.
 *
 * `TENANT_DB` + `TENANT_DB_TENANT_ID` point exactly one tenant at a physical
 * database created BY HAND in cPanel (e.g. `lbacloud_tenant`). This suite proves
 * that:
 *  - the override is inert unless BOTH values are set, and only the designated
 *    tenant is affected — every other tenant keeps its own derived database;
 *  - the name and the id are validated (identifier rules, ULID) and may never be
 *    the central platform database;
 *  - the SAME resolver feeds the runtime connection, "Uji Koneksi", migration
 *    and the registry check, and a conflict with the central registry is
 *    reported and DENIED before any migration;
 *  - the designated tenant is provisioned by the MANUAL, verify-only adapter,
 *    so the cPanel UAPI is never called, and it reaches "Siap" only after the
 *    migrations run.
 */
class ManualTenantDatabaseOverrideTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, BuildsTenantDatabases, RefreshDatabase;

    /** @var list<string> */
    private array $manualFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        foreach ($this->manualFiles as $file) {
            File::delete($file);
        }

        $this->manualFiles = [];

        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    /** Turn the override ON for exactly one tenant. */
    private function designate(string $database, string $tenantId): void
    {
        config()->set('tenancy.manual_database.database', $database);
        config()->set('tenancy.manual_database.tenant_id', $tenantId);
    }

    private function base(Tenant $tenant): string
    {
        return "/principal/master-tenants/{$tenant->getKey()}/relations";
    }

    /**
     * Create the physical database the manual name refers to (a SQLite file in
     * the sandbox, standing in for the hand-created MySQL schema).
     */
    private function createManualDatabaseFile(string $name): string
    {
        $path = base_path($name);
        File::put($path, '');
        $this->manualFiles[] = $path;

        return $path;
    }

    // -------------------------------------------------------- Name resolution

    public function test_the_designated_tenant_uses_the_manual_database_while_others_keep_their_own(): void
    {
        $selected = Tenant::factory()->withoutDatabase()->create();
        $other = Tenant::factory()->withoutDatabase()->create();

        $this->designate('lbacloud_tenant', (string) $selected->getKey());

        $names = app(TenantDatabaseName::class);

        // The designated tenant resolves to the configured physical name.
        $this->assertSame('lbacloud_tenant', $names->for((string) $selected->getKey()));
        $this->assertSame('lbacloud_tenant', $names->manualName((string) $selected->getKey()));

        // Every OTHER tenant is untouched: no manual name, and its own derived
        // per-tenant database instead.
        $this->assertNull($names->manualName((string) $other->getKey()));

        $own = $names->for((string) $other->getKey());
        $this->assertNotSame('lbacloud_tenant', $own);
        $this->assertStringEndsWith(strtolower((string) $other->getKey()).'.sqlite', $own);
    }

    public function test_the_override_is_inert_until_both_values_are_set(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $id = (string) $tenant->getKey();
        $names = app(TenantDatabaseName::class);

        // Only the database name is set.
        config()->set('tenancy.manual_database.database', 'lbacloud_tenant');
        config()->set('tenancy.manual_database.tenant_id', null);
        $this->assertNull($names->manualName($id));

        // Only the designated id is set.
        config()->set('tenancy.manual_database.database', null);
        config()->set('tenancy.manual_database.tenant_id', $id);
        $this->assertNull($names->manualName($id));

        // A blank name is the same as unset.
        config()->set('tenancy.manual_database.database', '');
        $this->assertNull($names->manualName($id));
    }

    public function test_a_registry_row_that_matches_the_resolved_name_is_not_a_conflict(): void
    {
        $selected = Tenant::factory()->withoutDatabase()->create();
        $id = (string) $selected->getKey();

        $this->designate('lbacloud_tenant', $id);

        TenantDatabase::query()->create([
            'tenant_id' => $selected->getKey(),
            'database_name' => 'lbacloud_tenant',
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $conflicts = app(TenantDatabaseConflict::class);

        $this->assertNull($conflicts->for($id));
        $this->assertNull($conflicts->for((string) Tenant::factory()->withoutDatabase()->create()->getKey()));
    }

    // ----------------------------------------------------------- Validation

    public function test_a_designated_id_that_is_not_a_ulid_is_rejected(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $this->designate('lbacloud_tenant', 'not-a-ulid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TENANT_DB_TENANT_ID must be a valid ULID');

        app(TenantDatabaseName::class)->for((string) $tenant->getKey());
    }

    public function test_a_manual_database_that_is_not_a_valid_identifier_is_rejected(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $this->designate('not a valid name', (string) $tenant->getKey());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid MySQL/cPanel identifier');

        app(TenantDatabaseName::class)->for((string) $tenant->getKey());
    }

    public function test_the_manual_database_may_never_be_the_central_database(): void
    {
        $tenant = Tenant::factory()->withoutDatabase()->create();

        $central = (string) config('tenancy.central_connection');
        config()->set("database.connections.{$central}.database", 'lbacloud_tenant');

        $this->designate('lbacloud_tenant', (string) $tenant->getKey());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not name the central platform database');

        app(TenantDatabaseName::class)->for((string) $tenant->getKey());
    }

    // ------------------------------------------- Conflicts rejected up front

    public function test_a_name_registered_to_another_tenant_is_rejected_before_migrating(): void
    {
        Http::fake();

        $selected = Tenant::factory()->withoutDatabase()->create();
        $other = Tenant::factory()->withoutDatabase()->create();

        // The physical name is already the registry's database for ANOTHER tenant.
        TenantDatabase::query()->create([
            'tenant_id' => $other->getKey(),
            'database_name' => 'lbacloud_tenant',
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $this->designate('lbacloud_tenant', (string) $selected->getKey());

        $this->actingAs($this->principal())
            ->post($this->base($selected).'/database/migrate')
            ->assertRedirect();

        // Fail closed BEFORE any migration: no registry row is created for the
        // selected tenant, and the conflict is reported for the operator.
        $this->assertDatabaseMissing('tenant_databases', ['tenant_id' => $selected->getKey()]);
        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.tenancy',
            'action' => 'provision_database',
            'result' => 'denied',
            'scope_key' => (string) $selected->getKey(),
            'reason' => TenantDatabaseConflict::REGISTERED_TO_ANOTHER_TENANT,
        ]);

        Http::assertNothingSent();
    }

    public function test_a_recorded_database_that_differs_is_rejected_before_migrating(): void
    {
        $selected = Tenant::factory()->withoutDatabase()->create();

        TenantDatabase::query()->create([
            'tenant_id' => $selected->getKey(),
            'database_name' => 'legacy_selected_db',
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $this->designate('lbacloud_tenant', (string) $selected->getKey());

        $this->actingAs($this->principal())
            ->post($this->base($selected).'/database/migrate')
            ->assertRedirect();

        // The recorded name (and its data) is preserved — never re-pointed at the
        // differently resolved schema.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $selected->getKey(),
            'database_name' => 'legacy_selected_db',
        ]);
        $this->assertDatabaseHas('module_audit_logs', [
            'scope_key' => (string) $selected->getKey(),
            'reason' => TenantDatabaseConflict::RECORDED_DATABASE_MISMATCH,
        ]);
    }

    public function test_an_unusable_configured_name_is_rejected_before_migrating(): void
    {
        $selected = Tenant::factory()->withoutDatabase()->create();
        $this->designate('not a valid name', (string) $selected->getKey());

        $this->actingAs($this->principal())
            ->post($this->base($selected).'/database/migrate')
            ->assertRedirect();

        // The invalid name stops the run before anything is provisioned or
        // registered, and the failure is surfaced to the operator.
        $this->assertDatabaseMissing('tenant_databases', ['tenant_id' => $selected->getKey()]);
        $this->assertDatabaseHas('module_audit_logs', [
            'scope_key' => (string) $selected->getKey(),
            'action' => 'provision_database',
            'result' => 'denied',
        ]);

        $result = session('database_result');
        $this->assertNotNull($result['error'] ?? null);
    }

    // ------------------------------------------------------- Connection test

    public function test_the_connection_test_reports_a_conflict_without_touching_the_database(): void
    {
        Http::fake();

        $selected = Tenant::factory()->withoutDatabase()->create();
        $other = Tenant::factory()->withoutDatabase()->create();

        TenantDatabase::query()->create([
            'tenant_id' => $other->getKey(),
            'database_name' => 'lbacloud_tenant',
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $this->designate('lbacloud_tenant', (string) $selected->getKey());

        $this->actingAs($this->principal())
            ->post($this->base($selected).'/database/test')
            ->assertRedirect();

        Http::assertNothingSent();

        $probe = session('database_probe');
        $this->assertSame('lbacloud_tenant', $probe['target']);
        $this->assertFalse($probe['connected']);
        $this->assertSame(TenantDatabaseConflict::REGISTERED_TO_ANOTHER_TENANT, $probe['reason']);
    }

    // --------------------------------------------------- Manual, UAPI-free path

    public function test_the_designated_tenant_is_provisioned_on_the_manual_database_without_the_cpanel_uapi(): void
    {
        Http::fake();

        $selected = Tenant::factory()->withoutDatabase()->create();
        $this->designate('lbacloud_tenant', (string) $selected->getKey());
        $this->createManualDatabaseFile('lbacloud_tenant');

        $this->actingAs($this->principal())
            ->post($this->base($selected).'/database/migrate')
            ->assertRedirect();

        // Manual mode NEVER issues a cPanel UAPI request.
        Http::assertNothingSent();

        // "Siap" is reached because the migrations ran against the manual
        // database and the registry recorded it under the manual name.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $selected->getKey(),
            'database_name' => 'lbacloud_tenant',
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $this->actingAs($this->principal())
            ->get($this->base($selected))
            ->assertOk()
            ->assertSee('lbacloud_tenant')
            ->assertSee(__('principal.tenants.database.state_ready'));
    }

    public function test_the_override_aware_provisioner_routes_only_the_designated_tenant_to_the_manual_adapter(): void
    {
        $selected = Tenant::factory()->withoutDatabase()->create();
        $other = Tenant::factory()->withoutDatabase()->create();

        $this->designate('lbacloud_tenant', (string) $selected->getKey());

        $default = new class implements ProvisionsTenantDatabases
        {
            /** @var list<array{0: string, 1: string}> */
            public array $calls = [];

            public function provision(string $tenantId): void
            {
                $this->calls[] = ['provision', $tenantId];
            }

            public function drop(string $tenantId): void
            {
                $this->calls[] = ['drop', $tenantId];
            }

            public function exists(string $tenantId): bool
            {
                $this->calls[] = ['exists', $tenantId];

                return true;
            }
        };

        $manual = new class implements ProvisionsTenantDatabases
        {
            /** @var list<array{0: string, 1: string}> */
            public array $calls = [];

            public function provision(string $tenantId): void
            {
                $this->calls[] = ['provision', $tenantId];
            }

            public function drop(string $tenantId): void
            {
                $this->calls[] = ['drop', $tenantId];
            }

            public function exists(string $tenantId): bool
            {
                $this->calls[] = ['exists', $tenantId];

                return true;
            }
        };

        $router = new OverrideAwareTenantDatabaseProvisioner($default, $manual, app(TenantDatabaseName::class));

        $router->provision((string) $selected->getKey());
        $router->exists((string) $other->getKey());

        // The designated tenant goes through the MANUAL (verify-only) adapter —
        // the cPanel UAPI is never involved — while the other tenant keeps the
        // mode's default adapter.
        $this->assertSame([['provision', (string) $selected->getKey()]], $manual->calls);
        $this->assertSame([['exists', (string) $other->getKey()]], $default->calls);
    }

    public function test_another_tenant_still_resolves_to_and_reaches_ready_on_its_own_database(): void
    {
        $selected = Tenant::factory()->withoutDatabase()->create();
        $other = Tenant::factory()->withoutDatabase()->create();

        $this->designate('lbacloud_tenant', (string) $selected->getKey());

        $names = app(TenantDatabaseName::class);
        $otherId = (string) $other->getKey();

        $this->assertNull($names->manualName($otherId));
        $this->assertNotSame('lbacloud_tenant', $names->for($otherId));

        // The other tenant is provisioned and migrated on its OWN derived
        // database, reaching Ready under its own name.
        $result = app(ClinicSetupService::class)->provisionDatabase($other);

        $this->assertTrue($result->ready);
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $otherId,
            'database_name' => $names->for($otherId),
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);
        $this->assertDatabaseMissing('tenant_databases', [
            'tenant_id' => $otherId,
            'database_name' => 'lbacloud_tenant',
        ]);
    }
}
