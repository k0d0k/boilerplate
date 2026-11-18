<?php

namespace Tests\Feature\Principal;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Access\TenantMembershipAuthorizer;
use App\Platform\Settings\PlatformSettings;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\Models\TenantDatabaseCredential;
use App\Platform\Tenancy\TenantDatabaseName;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * v3.3 — Principal manual-database lifecycle (Phase B verification).
 *
 * Proves the operator journey the staging runbook depends on:
 *
 *   Brand -> Partner (brand link + MANUAL database) -> "Uji Koneksi"
 *   (read-only) -> "Setup Klinik" (migrate -> Ready) -> Clinic Admin login ->
 *   link a dummy patient -> Patient portal login
 *
 * and the two destructive governance actions:
 *
 *   - "Hapus Mitra" is a REVERSIBLE soft delete: access stops at once, central
 *     child rows (above all the `tenant_databases` registry) survive so the
 *     physical name stays CLAIMED and a retained clinical schema can never be
 *     re-opened by a new partner. NO `DROP DATABASE` is ever run.
 *   - "Hapus Brand" is blocked while a partner still links it (the reason names
 *     the partners) and only then removes the brand — never a partner, account,
 *     subscription or clinical database.
 *
 * Both actions are gated to the Principal Super Admin server-side. Every
 * identity, partner, database and patient here is synthetic (DECISIONS.md
 * PRV-01); only the local SQLite test engine is used — no cPanel host, no UAPI.
 */
class PrincipalManualDatabaseLifecycleTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsPrincipalGovernance, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
        $this->bootTenantDatabases();
        Storage::fake('lba');
        Storage::fake('lba-reports');
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function enableManualMode(): void
    {
        app(PlatformSettings::class)->setDatabaseMode(PlatformSettings::MODE_MANUAL, null);

        // The provisioner is a singleton resolved per request; drop the cached
        // instance so the next request re-reads the new mode.
        $this->app->forgetInstance(ProvisionsTenantDatabases::class);
    }

    private function relationsBase(Tenant $tenant): string
    {
        return "/principal/master-tenants/{$tenant->getKey()}/relations";
    }

    /** Simulate the database an operator created by hand in cPanel. */
    private function createManualDatabaseFile(Tenant $tenant): string
    {
        $target = app(TenantDatabaseName::class)->for((string) $tenant->getKey());
        File::ensureDirectoryExists(dirname($target));
        File::put($target, '');

        return $target;
    }

    private function bootPanel(string $id): void
    {
        $panel = Filament::getPanel($id);
        Filament::setCurrentPanel($panel);
        $panel->boot();
    }

    /**
     * Submit a login form the way a browser does.
     *
     * The staff login and the patient login are separate boundaries: staff sign
     * in at `/login`, patients at `/patient/login`. Each rejects the other's
     * identity, so the helper takes the boundary it must render.
     */
    private function submitLogin(string $email, string $password, string $path = '/login'): TestResponse
    {
        // Start from a guest session: an earlier actingAs() would make the login
        // page redirect away instead of rendering the form.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $html = $this->get($path)->assertOk()->getContent();

        if (! preg_match('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The login page did not render a Livewire snapshot.');
        }

        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'calls' => [['method' => 'authenticate', 'params' => [], 'path' => '']],
                'updates' => ['data.email' => $email, 'data.password' => $password],
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Brand link + MANUAL database on "Tambah Mitra"
    |--------------------------------------------------------------------------
    */

    public function test_one_brand_can_support_two_partners_with_different_databases(): void
    {
        $this->enableManualMode();
        $actor = $this->principal();
        $brand = Brand::factory()->create(['name' => 'NuBiom']);

        $this->actingAs($actor)->post('/principal/master-tenants', [
            'name' => 'Klinik NuBiom Staging',
            'slug' => 'klinik-nubiom-staging',
            'status' => Tenant::STATUS_ACTIVE,
            'brand_id' => $brand->getKey(),
            'is_primary_brand' => '1',
            'database_mode' => PlatformSettings::MODE_MANUAL,
            'db_name' => 'lbacloud_tenant',
            'db_username' => 'lba_partner',
            'db_password' => 'staging-secret',
        ])->assertRedirect();

        $first = Tenant::query()->where('slug', 'klinik-nubiom-staging')->sole();

        // (a) The existing Brand–Partner relation is used, and marked primary.
        $this->assertDatabaseHas('tenant_brands', [
            'tenant_id' => $first->getKey(),
            'brand_id' => $brand->getKey(),
            'is_primary' => true,
        ]);

        // (b) The MANUAL target is recorded as Menunggu — nothing is created,
        //     migrated or even contacted at this point.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $first->getKey(),
            'database_name' => app(TenantDatabaseName::class)->targetForConfiguredName('lbacloud_tenant'),
            'configured_database_name' => 'lbacloud_tenant',
            'status' => TenantDatabase::STATUS_PENDING,
        ]);

        // A SECOND partner chooses the SAME brand with a DIFFERENT database.
        $this->actingAs($actor)->post('/principal/master-tenants', [
            'name' => 'Klinik NuBiom Kedua',
            'slug' => 'klinik-nubiom-kedua',
            'status' => Tenant::STATUS_ACTIVE,
            'brand_id' => $brand->getKey(),
            'is_primary_brand' => '1',
            'database_mode' => PlatformSettings::MODE_MANUAL,
            'db_name' => 'lbacloud_tenant_2',
            'db_username' => 'lba_partner_2',
            'db_password' => 'staging-secret-2',
        ])->assertRedirect();

        $second = Tenant::query()->where('slug', 'klinik-nubiom-kedua')->sole();

        // One shared brand, two DIFFERENT physical databases.
        $this->assertSame(2, $brand->tenants()->count());
        $this->assertSame('lbacloud_tenant', $first->database->configured_database_name);
        $this->assertSame('lbacloud_tenant_2', $second->database->configured_database_name);
        $this->assertNotSame($first->database->database_name, $second->database->database_name);
    }

    public function test_the_central_database_name_and_a_claimed_name_are_refused_without_flashing_the_password(): void
    {
        $this->enableManualMode();
        $actor = $this->principal();
        $password = 'must-never-be-flashed';

        // The CENTRAL platform database may never become a partner database.
        config()->set(
            'database.connections.'.config('tenancy.central_connection').'.database',
            'lba_central',
        );

        $this->actingAs($actor)->post('/principal/master-tenants', [
            'name' => 'Mitra Pusat',
            'slug' => 'mitra-pusat',
            'status' => Tenant::STATUS_ACTIVE,
            'database_mode' => PlatformSettings::MODE_MANUAL,
            'db_name' => 'lba_central',
            'db_username' => 'lba_partner',
            'db_password' => $password,
        ])->assertSessionHasErrors([
            'db_name' => __('principal.tenants.database.config_error_central_database'),
        ]);

        $this->assertDatabaseMissing('tenants', ['slug' => 'mitra-pusat']);
        // The write-only credential is NEVER flashed back on a failed submit.
        $this->assertArrayNotHasKey('db_password', (array) session('_old_input'));

        // A name already CLAIMED by another partner is refused too.
        $other = Tenant::factory()->withoutDatabase()->create();
        TenantDatabase::query()->create([
            'tenant_id' => $other->getKey(),
            'database_name' => app(TenantDatabaseName::class)->targetForConfiguredName('claimed_db'),
            'configured_database_name' => 'claimed_db',
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $this->actingAs($actor)->post('/principal/master-tenants', [
            'name' => 'Mitra Klaim',
            'slug' => 'mitra-klaim',
            'status' => Tenant::STATUS_ACTIVE,
            'database_mode' => PlatformSettings::MODE_MANUAL,
            'db_name' => 'claimed_db',
            'db_username' => 'lba_partner',
            'db_password' => $password,
        ])->assertSessionHasErrors([
            'db_name' => __('principal.tenants.database.config_error_registered_to_another_tenant'),
        ]);

        $this->assertDatabaseMissing('tenants', ['slug' => 'mitra-klaim']);
        $this->assertArrayNotHasKey('db_password', (array) session('_old_input'));
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Credential lifecycle on the Partner detail (relations) screen
    |--------------------------------------------------------------------------
    */

    public function test_a_super_admin_stores_an_encrypted_write_only_credential_from_the_relations_screen(): void
    {
        $this->enableManualMode();
        $actor = $this->principal();
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $base = $this->relationsBase($tenant);
        $password = 'relations-super-secret';

        $this->actingAs($actor)->post("{$base}/database/credentials", [
            'db_name' => 'lbacloud_tenant',
            'db_username' => 'lba_partner',
            'db_password' => $password,
        ])->assertRedirect();

        // Ciphertext at rest: the plaintext never appears in the stored column.
        $row = DB::table('tenant_database_credentials')->where('tenant_id', $tenant->getKey())->sole();
        $this->assertStringNotContainsString($password, (string) $row->password);

        // The model decrypts it transparently; the target was recorded too.
        $credential = TenantDatabaseCredential::query()->where('tenant_id', $tenant->getKey())->sole();
        $this->assertSame('lba_partner', $credential->username);
        $this->assertSame($password, $credential->password);

        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'configured_database_name' => 'lbacloud_tenant',
        ]);

        // The password is NEVER echoed back on the screen.
        $this->actingAs($actor)->get($base)->assertOk()->assertDontSee($password);

        // A blank password preserves the stored one; the username still updates.
        $this->actingAs($actor)->post("{$base}/database/credentials", [
            'db_username' => 'lba_partner_2',
            'db_password' => '',
        ])->assertRedirect();

        $credential->refresh();
        $this->assertSame('lba_partner_2', $credential->username);
        $this->assertSame($password, $credential->password);
    }

    public function test_a_claimed_name_is_refused_on_the_relations_screen_without_flashing_the_password(): void
    {
        $this->enableManualMode();
        $actor = $this->principal();
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $other = Tenant::factory()->withoutDatabase()->create();

        TenantDatabase::query()->create([
            'tenant_id' => $other->getKey(),
            'database_name' => app(TenantDatabaseName::class)->targetForConfiguredName('taken_db'),
            'configured_database_name' => 'taken_db',
            'driver' => 'sqlite',
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $this->actingAs($actor)->post($this->relationsBase($tenant).'/database/credentials', [
            'db_name' => 'taken_db',
            'db_username' => 'lba_partner',
            'db_password' => 'never-flashed',
        ])->assertSessionHasErrors([
            'db_name' => __('principal.tenants.database.config_error_registered_to_another_tenant'),
        ]);

        $this->assertDatabaseMissing('tenant_database_credentials', ['tenant_id' => $tenant->getKey()]);
        $this->assertArrayNotHasKey('db_password', (array) session('_old_input'));
    }

    public function test_a_migrated_partners_recorded_database_is_never_repointed(): void
    {
        $this->enableManualMode();
        $actor = $this->principal();
        // The factory registry is already MIGRATED, so the partner is live.
        $tenant = Tenant::factory()->create();
        $recorded = TenantDatabase::query()->where('tenant_id', $tenant->getKey())->sole();

        $this->actingAs($actor)->post($this->relationsBase($tenant).'/database/credentials', [
            'db_name' => 'another_db',
            'db_username' => 'lba_partner',
            'db_password' => 'x',
        ])->assertSessionHasErrors([
            'db_name' => __('principal.tenants.database.config_error_recorded_database_mismatch'),
        ]);

        // The registered name (and therefore its data) is preserved verbatim.
        $this->assertSame(
            $recorded->database_name,
            TenantDatabase::query()->where('tenant_id', $tenant->getKey())->sole()->database_name,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. "Hapus Mitra" — reversible, name-preserving, PSA-only
    |--------------------------------------------------------------------------
    */

    public function test_partner_removal_form_does_not_depend_on_javascript_assets(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->principal())
            ->get('/principal/master-tenants')
            ->assertOk()
            ->assertSee('<details', false)
            ->assertSee(route('principal.tailadmin.tenants.destroy', $tenant), false)
            ->assertSee('name="_method" value="DELETE"', false);
    }

    public function test_removing_a_partner_soft_deletes_it_and_keeps_its_database_claimed(): void
    {
        $this->enableManualMode();
        $actor = $this->principal();

        $this->actingAs($actor)->post('/principal/master-tenants', [
            'name' => 'Klinik NuBiom Staging',
            'slug' => 'klinik-nubiom-staging',
            'status' => Tenant::STATUS_ACTIVE,
            'database_mode' => PlatformSettings::MODE_MANUAL,
            'db_name' => 'lbacloud_tenant',
            'db_username' => 'lba_partner',
            'db_password' => 'staging-secret',
        ])->assertRedirect();

        $tenant = Tenant::query()->where('slug', 'klinik-nubiom-staging')->sole();
        $target = $this->createManualDatabaseFile($tenant);

        $this->actingAs($actor)
            ->delete("/principal/master-tenants/{$tenant->getKey()}")
            ->assertRedirect(route('principal.tailadmin.tenants.index'));

        // Soft delete: the central row is flagged removed...
        $this->assertSoftDeleted('tenants', ['id' => $tenant->getKey()]);
        // ...but the registry row and the physical schema survive, so the name
        // stays CLAIMED — no `DROP DATABASE`, no emptied cPanel schema.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'configured_database_name' => 'lbacloud_tenant',
        ]);
        $this->assertTrue(File::exists($target));

        // A NEW partner may NOT take over the retained name (and its data).
        $this->actingAs($actor)->post('/principal/master-tenants', [
            'name' => 'Klinik Pengganti',
            'slug' => 'klinik-pengganti',
            'status' => Tenant::STATUS_ACTIVE,
            'database_mode' => PlatformSettings::MODE_MANUAL,
            'db_name' => 'lbacloud_tenant',
            'db_username' => 'lba_other',
            'db_password' => 'other-secret',
        ])->assertSessionHasErrors([
            'db_name' => __('principal.tenants.database.config_error_registered_to_another_tenant'),
        ]);

        $this->assertDatabaseMissing('tenants', ['slug' => 'klinik-pengganti']);
    }

    public function test_removing_a_partner_stops_that_access_but_not_another_partners(): void
    {
        $actor = $this->principal();
        $removed = Tenant::factory()->create();
        $kept = Tenant::factory()->create();
        $user = User::factory()->create();
        $this->giveMembership($user, $removed, [RoleKey::TENANT_ADMIN]);
        $this->giveMembership($user, $kept, [RoleKey::TENANT_ADMIN]);

        $authorizer = app(TenantMembershipAuthorizer::class);
        $this->assertTrue($authorizer->isMemberOf($user, (string) $removed->getKey()));

        $this->actingAs($actor)
            ->delete("/principal/master-tenants/{$removed->getKey()}")
            ->assertRedirect(route('principal.tailadmin.tenants.index'));

        // Access to the removed partner stops at once...
        $this->assertFalse($authorizer->isMemberOf($user, (string) $removed->getKey()));
        // ...while an account that also belongs to another partner keeps working.
        $this->assertTrue($authorizer->isMemberOf($user, (string) $kept->getKey()));
    }

    public function test_removing_a_partner_is_refused_while_a_live_subscription_exists(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create();

        $plan = SubscriptionPlan::query()->create([
            'code' => 'plan-'.uniqid(),
            'name' => 'Klinik Plan',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => '250000.00',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 0,
            'module_keys' => [],
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => TenantSubscription::STATUS_ACTIVE,
            'seats' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($actor)
            ->delete("/principal/master-tenants/{$tenant->getKey()}")
            ->assertRedirect(route('principal.tailadmin.tenants.index'))
            ->assertSessionHasErrors('delete');

        $this->assertNotSoftDeleted('tenants', ['id' => $tenant->getKey()]);
    }

    public function test_removing_a_partner_is_forbidden_without_super_admin(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))
            ->delete("/principal/master-tenants/{$tenant->getKey()}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('tenants', ['id' => $tenant->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. "Hapus Brand" — blocked by links, PSA-only, never touches partners
    |--------------------------------------------------------------------------
    */

    public function test_brand_removal_form_does_not_depend_on_javascript_assets(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs($this->principal())
            ->get('/principal/master-brands')
            ->assertOk()
            ->assertSee('<details', false)
            ->assertSee(route('principal.tailadmin.brands.destroy', $brand), false)
            ->assertSee('name="_method" value="DELETE"', false);
    }

    public function test_removing_a_brand_is_blocked_while_a_partner_uses_it_and_succeeds_after_detaching(): void
    {
        $actor = $this->principal();
        $brand = Brand::factory()->create(['name' => 'NuBiom']);
        $tenant = Tenant::factory()->create();
        $tenant->brands()->attach($brand->getKey(), ['is_primary' => true]);

        $this->actingAs($actor)
            ->delete("/principal/master-brands/{$brand->getKey()}")
            ->assertRedirect(route('principal.tailadmin.brands.index'))
            ->assertSessionHasErrors('delete');

        // The brand — and above all the partner, its account and its database —
        // are untouched while the link exists.
        $this->assertDatabaseHas('brands', ['id' => $brand->getKey()]);
        $this->assertNotSoftDeleted('tenants', ['id' => $tenant->getKey()]);
        $this->assertDatabaseHas('tenant_databases', ['tenant_id' => $tenant->getKey()]);

        // Releasing the relation unblocks removal.
        $tenant->brands()->detach($brand->getKey());

        $this->actingAs($actor)
            ->delete("/principal/master-brands/{$brand->getKey()}")
            ->assertRedirect(route('principal.tailadmin.brands.index'));

        $this->assertDatabaseMissing('brands', ['id' => $brand->getKey()]);
        // The partner (and its database registry) still exists.
        $this->assertDatabaseHas('tenants', ['id' => $tenant->getKey()]);
    }

    public function test_a_soft_deleted_partner_no_longer_blocks_removing_its_brand(): void
    {
        $actor = $this->principal();
        $brand = Brand::factory()->create(['name' => 'NuBiom']);
        $tenant = Tenant::factory()->create();
        $tenant->brands()->attach($brand->getKey(), ['is_primary' => true]);

        // "Hapus Mitra" is a soft delete; the brand-link check reads the
        // relation through Tenant's SoftDeletes scope, so a removed partner is
        // invisible there while the pivot row survives untouched.
        $tenant->delete();

        $this->assertSame(0, $brand->fresh()->tenants()->count());
        $this->assertDatabaseHas('tenant_brands', [
            'tenant_id' => $tenant->getKey(),
            'brand_id' => $brand->getKey(),
        ]);

        // Therefore the removed partner no longer blocks "Hapus Brand".
        $this->actingAs($actor)
            ->delete("/principal/master-brands/{$brand->getKey()}")
            ->assertRedirect(route('principal.tailadmin.brands.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('brands', ['id' => $brand->getKey()]);
        // The soft-deleted partner — and its database claim — are untouched.
        $this->assertSoftDeleted('tenants', ['id' => $tenant->getKey()]);
        $this->assertDatabaseHas('tenant_databases', ['tenant_id' => $tenant->getKey()]);
    }

    public function test_removing_a_brand_is_forbidden_without_super_admin(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))
            ->delete("/principal/master-brands/{$brand->getKey()}")
            ->assertForbidden();

        $this->assertDatabaseHas('brands', ['id' => $brand->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Staging flow end to end: brand -> partner -> probe -> migrate ->
    |    Clinic Admin login -> link patient -> Patient portal login
    |--------------------------------------------------------------------------
    */

    public function test_the_staging_flow_from_brand_to_patient_portal(): void
    {
        Http::fake();
        $this->enableManualMode();
        $actor = $this->principal();

        // (1) Principal creates the brand NuBiom.
        $this->actingAs($actor)->post('/principal/master-brands', [
            'name' => 'NuBiom',
            'slug' => 'nubiom',
            'status' => Brand::STATUS_ACTIVE,
        ])->assertRedirect(route('principal.tailadmin.brands.index'));

        $brand = Brand::query()->where('slug', 'nubiom')->sole();

        // (2) Principal creates "Klinik NuBiom Staging", choosing the brand and
        //     a MANUAL database created by hand in cPanel.
        $this->actingAs($actor)->post('/principal/master-tenants', [
            'name' => 'Klinik NuBiom Staging',
            'slug' => 'klinik-nubiom-staging',
            'status' => Tenant::STATUS_ACTIVE,
            'brand_id' => $brand->getKey(),
            'is_primary_brand' => '1',
            'database_mode' => PlatformSettings::MODE_MANUAL,
            'db_name' => 'lbacloud_tenant',
            'db_username' => 'lba_partner',
            'db_password' => 'staging-secret',
        ])->assertRedirect();

        $tenant = Tenant::query()->where('slug', 'klinik-nubiom-staging')->sole();
        $tenantId = (string) $tenant->getKey();
        $base = $this->relationsBase($tenant);

        // The operator created this database in cPanel beforehand.
        $this->createManualDatabaseFile($tenant);

        // (3) "Uji Koneksi" is READ-ONLY and calls no cPanel UAPI.
        $this->actingAs($actor)->post("{$base}/database/test")->assertRedirect();
        Http::assertNothingSent();

        $probe = session('database_probe');
        $this->assertTrue($probe['connected']);
        $this->assertTrue($probe['database_present']);

        // (4) "Setup Klinik" (migrate) reaches Ready — only after migrating.
        $this->actingAs($actor)->post("{$base}/database/migrate")->assertRedirect();

        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenantId,
            'status' => TenantDatabase::STATUS_MIGRATED,
            'configured_database_name' => 'lbacloud_tenant',
        ]);

        $this->actingAs($actor)->get($base)
            ->assertOk()
            ->assertSee(__('principal.tenants.database.state_ready'));

        // (5) The Clinic Admin signs in and reaches the clinic dashboard.
        $admin = User::factory()->role(RoleKey::TENANT_ADMIN)
            ->create(['email' => 'clinic.admin@example.test']);
        $this->giveMembership($admin, $tenant, [RoleKey::TENANT_ADMIN]);

        $this->bootPanel('clinic');

        $login = $this->submitLogin('clinic.admin@example.test', 'password');
        $login->assertOk();
        $this->assertSame('/clinic', $login->json('components.0.effects.redirect'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->bootPanel('clinic');

        $this->actingAs($admin->refresh())
            ->get('/clinic')
            ->assertOk()
            ->assertSee('Klinik NuBiom Staging');

        // (6) The clinic links a DUMMY patient account through the proof-based
        //     linker (never a mass-assigned user_id).
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->forget();
        $this->tenantDatabases()->configure($tenantId);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Pasien Dummy Staging',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $patientUser = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->locale('id')
            ->create(['email' => 'pasien@example.test']);
        $this->giveMembership($patientUser, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        $this->tenantDatabases()->forget();
        $this->tenantDatabases()->configure($tenantId);

        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $owner);
        app(PatientAccountLinker::class)->redeemInvitation($patientUser, $code);

        // (7) The patient signs in to the portal.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->bootPanel('patient');

        $patientLogin = $this->submitLogin('pasien@example.test', 'password', '/patient/login');
        $patientLogin->assertOk();
        $this->assertSame('/patient', $patientLogin->json('components.0.effects.redirect'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->bootPanel('patient');

        $this->actingAs($patientUser->refresh())
            ->get('/patient')
            ->assertOk()
            ->assertSee('Beranda Pasien');
    }
}
