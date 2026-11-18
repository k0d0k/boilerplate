<?php

namespace Tests\Feature\Principal;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Manual\ManualTenantDatabaseProvisioner;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\Models\TenantDatabaseCredential;
use App\Platform\Tenancy\TenantDatabaseName;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 3 — MANUAL database mode ("Database Manual").
 *
 * Proves that manual mode:
 *  - is chosen in Pengaturan Global → Hosting and NEVER calls the cPanel UAPI;
 *  - derives the tenant's PHYSICAL database name from the partner id and the
 *    hosting prefix, so it can only ever be the partner's own schema;
 *  - stores one encrypted, write-only MySQL credential per partner and overlays
 *    it onto the tenant connection, while a partner without a stored credential
 *    keeps the legacy `.env` credential (and its registered name) unchanged;
 *  - restricts every action to the Principal Super Admin, server-side;
 *  - keeps "Uji Koneksi" READ-ONLY, and reaches "Siap" only AFTER a successful
 *    migration through the existing tenant path.
 */
class PrincipalManualDatabaseTest extends TestCase
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

    private function enableManualMode(): void
    {
        app(PlatformSettings::class)->setDatabaseMode(PlatformSettings::MODE_MANUAL, null);
    }

    private function base(Tenant $tenant): string
    {
        return "/principal/master-tenants/{$tenant->getKey()}/relations";
    }

    private function createManualDatabaseFile(Tenant $tenant): string
    {
        $target = app(TenantDatabaseName::class)->for((string) $tenant->getKey());
        File::ensureDirectoryExists(dirname($target));
        File::put($target, '');

        return $target;
    }

    // -------------------------------------------------------------- Mode

    public function test_manual_mode_is_persisted_and_reported_on_the_hosting_tab(): void
    {
        $actor = $this->principal();

        config()->set('database.connections.tenant.host', 'db.internal.test');
        config()->set('database.connections.tenant.port', '3306');

        $this->actingAs($actor)
            ->patch('/principal/global-settings/hosting', ['mode' => PlatformSettings::MODE_MANUAL])
            ->assertRedirect();

        $settings = app(PlatformSettings::class);
        $this->assertSame(PlatformSettings::MODE_MANUAL, $settings->databaseMode());

        // The audit names the setting only.
        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => PlatformSettings::AUDIT_MODULE_KEY,
            'scope_key' => PlatformSettings::DATABASE_MODE,
            'actor_id' => $actor->getKey(),
        ]);

        // The screen shows the manual mode AND the MySQL host/port taken from the
        // server configuration (never from a cPanel UAPI call).
        $this->actingAs($actor)->get('/principal/global-settings?tab=hosting')
            ->assertOk()
            ->assertSee(__('principal.settings.hosting.mode_manual'))
            ->assertSee('db.internal.test')
            ->assertSee('3306');
    }

    public function test_manual_mode_is_served_by_the_manual_provisioner(): void
    {
        $this->assertNotInstanceOf(ManualTenantDatabaseProvisioner::class, app(ProvisionsTenantDatabases::class));

        $this->enableManualMode();

        // The provisioner is a singleton; a fresh container (i.e. a new request)
        // picks up the new mode.
        $this->app->forgetInstance(ProvisionsTenantDatabases::class);

        $this->assertInstanceOf(ManualTenantDatabaseProvisioner::class, app(ProvisionsTenantDatabases::class));
    }

    public function test_the_manual_physical_name_carries_the_hosting_account_prefix(): void
    {
        // MySQL/MariaDB only: names are account-prefixed exactly as cPanel
        // creates them, so the derived name matches the hand-created database.
        config()->set('database.connections.tenant.driver', 'mysql');
        config()->set('tenancy.cpanel.username', 'appmax');

        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();
        $names = app(TenantDatabaseName::class);

        $this->assertTrue(app(PlatformSettings::class)->databaseNamesPrefixed());
        $this->assertSame('appmax_'.$names->requestedName($tenantId), $names->for($tenantId));
    }

    // -------------------------------------------------- Provisioning / UAPI

    public function test_manual_mode_never_calls_the_cpanel_uapi_and_reaches_ready_only_after_migrating(): void
    {
        Http::fake();

        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $this->createManualDatabaseFile($tenant);

        $this->actingAs($this->principal())
            ->post($this->base($tenant).'/database/migrate')
            ->assertRedirect()
            ->assertSessionHas('database_result');

        // Manual mode NEVER issues a cPanel UAPI request.
        Http::assertNothingSent();

        // Ready is reached ONLY because the migration ran and registered the
        // database.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => TenantDatabase::STATUS_MIGRATED,
            'database_name' => app(TenantDatabaseName::class)->for((string) $tenant->getKey()),
        ]);

        $this->actingAs($this->principal())
            ->get($this->base($tenant))
            ->assertOk()
            ->assertSee(__('principal.tenants.database.state_ready'));
    }

    // ----------------------------------------------------- Credentials

    public function test_the_per_partner_credential_is_stored_encrypted_and_write_only(): void
    {
        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $password = 'manual-super-secret';

        $this->actingAs($this->principal())
            ->post($this->base($tenant).'/database/credentials', [
                'db_username' => 'lba_partner',
                'db_password' => $password,
            ])
            ->assertRedirect();

        // Ciphertext at rest: the plaintext never appears in the stored column.
        $row = DB::table('tenant_database_credentials')->where('tenant_id', $tenant->getKey())->sole();
        $this->assertStringNotContainsString($password, (string) $row->password);

        // The model transparently decrypts it.
        $credential = TenantDatabaseCredential::query()->where('tenant_id', $tenant->getKey())->sole();
        $this->assertSame('lba_partner', $credential->username);
        $this->assertSame($password, $credential->password);

        // The password is NEVER echoed back on the screen.
        $this->actingAs($this->principal())
            ->get($this->base($tenant))
            ->assertOk()
            ->assertDontSee($password);

        // A blank password preserves the stored value; the username still updates.
        $this->actingAs($this->principal())
            ->post($this->base($tenant).'/database/credentials', [
                'db_username' => 'lba_partner_2',
                'db_password' => '',
            ])
            ->assertRedirect();

        $credential->refresh();
        $this->assertSame('lba_partner_2', $credential->username);
        $this->assertSame($password, $credential->password);
    }

    public function test_the_tenant_connection_overlays_the_manual_credential_but_keeps_the_legacy_fallback(): void
    {
        config()->set('database.connections.tenant.username', 'env_user');
        config()->set('database.connections.tenant.password', 'env_pass');

        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        // A partner with a stored credential authenticates as its OWN user.
        TenantDatabaseCredential::query()->create([
            'tenant_id' => $tenantId,
            'username' => 'lba_partner',
            'password' => 'partner-pass',
        ]);

        $resolver = app(ResolvesTenantDatabase::class);
        $connection = $resolver->configure($tenantId);

        $this->assertSame('lba_partner', config("database.connections.{$connection}.username"));
        $this->assertSame('partner-pass', config("database.connections.{$connection}.password"));

        // Back in cPanel mode the templated `.env` credential applies unchanged.
        app(PlatformSettings::class)->setDatabaseMode(PlatformSettings::MODE_CPANEL, null);

        $resolver->forget();
        $connection = $resolver->configure($tenantId);

        $this->assertSame('env_user', config("database.connections.{$connection}.username"));
        $this->assertSame('env_pass', config("database.connections.{$connection}.password"));
    }

    public function test_a_partner_without_a_stored_credential_keeps_the_legacy_configuration(): void
    {
        config()->set('database.connections.tenant.username', 'env_user');
        config()->set('database.connections.tenant.password', 'env_pass');

        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $tenantId = (string) $tenant->getKey();

        $connection = app(ResolvesTenantDatabase::class)->configure($tenantId);

        $this->assertSame('env_user', config("database.connections.{$connection}.username"));
        $this->assertSame('env_pass', config("database.connections.{$connection}.password"));
    }

    public function test_one_partners_credential_never_leaks_into_another(): void
    {
        config()->set('database.connections.tenant.username', 'env_user');
        config()->set('database.connections.tenant.password', 'env_pass');

        $this->enableManualMode();

        $first = Tenant::factory()->withoutDatabase()->create();
        $second = Tenant::factory()->withoutDatabase()->create();

        TenantDatabaseCredential::query()->create([
            'tenant_id' => (string) $first->getKey(),
            'username' => 'lba_first',
            'password' => 'first-pass',
        ]);

        $resolver = app(ResolvesTenantDatabase::class);

        $firstConnection = $resolver->configure((string) $first->getKey());
        $this->assertSame('lba_first', config("database.connections.{$firstConnection}.username"));

        // Switching to a partner with no stored credential must fall back to the
        // legacy `.env` credential — never inherit the previous partner's.
        $secondConnection = $resolver->configure((string) $second->getKey());
        $this->assertSame('env_user', config("database.connections.{$secondConnection}.username"));
        $this->assertSame('env_pass', config("database.connections.{$secondConnection}.password"));
    }

    // -------------------------------------------------------------- Probe

    public function test_the_connection_test_is_read_only_and_leaks_no_credential(): void
    {
        Http::fake();

        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $password = 'probe-must-not-leak';
        $this->createManualDatabaseFile($tenant);

        TenantDatabaseCredential::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'username' => 'lba_probe',
            'password' => $password,
        ]);

        $this->actingAs($this->principal())
            ->post($this->base($tenant).'/database/test')
            ->assertRedirect()
            ->assertSessionHas('database_probe');

        // Read-only AND UAPI-free.
        Http::assertNothingSent();

        $probe = session('database_probe');
        $this->assertTrue($probe['connected']);
        $this->assertTrue($probe['database_present']);

        // No credential is ever surfaced in the projection.
        $this->assertStringNotContainsString($password, (string) json_encode($probe));
        $this->assertArrayNotHasKey('password', $probe);
    }

    // ------------------------------------------------------------ Safety

    public function test_the_database_section_targets_only_this_partner(): void
    {
        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $other = Tenant::factory()->create();
        $names = app(TenantDatabaseName::class);

        $target = $names->for((string) $tenant->getKey());

        $this->actingAs($this->principal())
            ->get($this->base($tenant))
            ->assertOk()
            ->assertSee(__('principal.tenants.database.heading'))
            ->assertSee($target)
            // Never the central database, never another partner's database.
            ->assertDontSee($names->for((string) $other->getKey()))
            ->assertDontSee((string) config('database.connections.'.config('tenancy.central_connection').'.database'));
    }

    public function test_a_registered_database_name_is_never_rewritten(): void
    {
        Http::fake();

        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();

        TenantDatabase::query()->create([
            'tenant_id' => $tenant->getKey(),
            'database_name' => 'legacy_partner_db',
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $this->actingAs($this->principal())
            ->post($this->base($tenant).'/database/migrate')
            ->assertRedirect();

        // The mismatch guard fails closed: the registered name (and its data) is
        // preserved, never silently re-pointed at a differently derived schema,
        // and NO database is provisioned under the newly derived name.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'database_name' => 'legacy_partner_db',
        ]);
        $this->assertFalse(File::exists(app(TenantDatabaseName::class)->for((string) $tenant->getKey())));

        $this->assertDatabaseHas('module_audit_logs', [
            'scope_key' => (string) $tenant->getKey(),
            'reason' => 'recorded_database_mismatch',
        ]);
    }

    public function test_database_actions_are_forbidden_without_super_admin(): void
    {
        $this->enableManualMode();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $base = $this->base($tenant);

        /** @var list<User> $denied */
        $denied = [
            $this->principal(RoleKey::PRINCIPAL_FINANCE),
            $this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN),
        ];

        foreach ($denied as $actor) {
            $this->actingAs($actor)->post("{$base}/database/credentials", ['db_username' => 'x'])->assertForbidden();
            $this->actingAs($actor)->post("{$base}/database/test")->assertForbidden();
            $this->actingAs($actor)->post("{$base}/database/migrate")->assertForbidden();
        }

        $this->assertDatabaseMissing('tenant_database_credentials', ['tenant_id' => $tenant->getKey()]);
    }
}
