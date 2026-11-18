<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Resources\TenantResource;
use App\Filament\Principal\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Principal\Resources\TenantResource\Pages\EditTenant;
use App\Filament\Principal\Resources\TenantResource\Pages\ListTenants;
use App\Filament\Principal\Resources\TenantResource\RelationManagers\BrandsRelationManager;
use App\Filament\Principal\Resources\TenantResource\RelationManagers\MembershipsRelationManager;
use App\Filament\Principal\Resources\TenantResource\RelationManagers\SubscriptionsRelationManager;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Tenancy\Models\TenantDatabase;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * Phase 7D — Principal tenant / clinic management (server layer).
 *
 * A tenant is one independent organisation with its own ISOLATED clinical
 * database (ARCH-04). This resource administers the central registry only —
 * lifecycle status, owner assignment, brand association and the provisioning
 * status of that isolated database — and is non-destructive: suspension never
 * drops a tenant database.
 *
 * Interaction is driven through the genuine Livewire round-trip (see
 * {@see DrivesPrincipalPanel}), because Filament 5's `Livewire::test()` helper
 * cannot perform follow-up calls against this application's full-page
 * components.
 */
class PrincipalTenantManagementTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, BuildsTenantDatabases, DrivesPrincipalPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // ------------------------------------------------------- Access control

    public function test_the_tenant_registry_boots_for_an_authorized_principal(): void
    {
        Tenant::factory()->create(['name' => 'Klinik Sejahtera']);

        $this->actingAs($this->principal())
            ->get('/principal/tenants')
            ->assertOk()
            ->assertSee('Klinik Sejahtera');
    }

    public function test_a_principal_without_the_tenant_permission_is_denied(): void
    {
        // principal_module_admin holds module/entitlement authority but not
        // `platform.tenant.manage`.
        $moduleAdmin = $this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN);

        $this->actingAs($moduleAdmin)->get('/principal/tenants')->assertForbidden();
    }

    // ----------------------------------------------------------------- CRUD

    public function test_a_principal_can_create_a_tenant(): void
    {
        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/create');

        $this->postLivewireCalls($html, CreateTenant::class, [
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'data.name' => 'Klinik Nusantara',
                'data.slug' => 'klinik-nusantara',
                'data.status' => Tenant::STATUS_ACTIVE,
            ], 'data']],
            ['method' => 'create', 'params' => []],
        ])->assertOk();

        $this->assertDatabaseHas('tenants', [
            'slug' => 'klinik-nusantara',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
    }

    // ------------------------------------------------------------- Lifecycle

    public function test_an_authorized_principal_can_suspend_and_reactivate_a_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);

        $this->actingAs($this->principal());

        $this->postLivewireCalls($this->renderPage('/principal/tenants'), ListTenants::class, [
            ['method' => 'mountAction', 'params' => ['suspend', [], ['table' => true, 'recordKey' => $tenant->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->refresh()->status);

        $this->postLivewireCalls($this->renderPage('/principal/tenants'), ListTenants::class, [
            ['method' => 'mountAction', 'params' => ['activate', [], ['table' => true, 'recordKey' => $tenant->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->refresh()->status);
    }

    public function test_tenants_are_never_deletable(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertFalse(TenantResource::canDelete($tenant));
        $this->assertFalse(TenantResource::canDeleteAny());
    }

    // ------------------------------------------------- Database provisioning

    public function test_the_setup_clinic_action_records_the_isolated_database_status(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->principal());

        $this->postLivewireCalls($this->renderPage('/principal/tenants'), ListTenants::class, [
            ['method' => 'mountAction', 'params' => ['setupClinic', [], ['table' => true, 'recordKey' => $tenant->getKey()]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.admin_email' => '',
                'mountedActions.0.data.admin_name' => '',
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);
    }

    public function test_suspending_a_tenant_preserves_its_isolated_database(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        TenantDatabase::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey()],
            [
                'database_name' => $this->tenantDatabaseFile($tenant),
                'driver' => 'sqlite',
                'status' => TenantDatabase::STATUS_MIGRATED,
                'last_migrated_at' => now(),
            ],
        );

        $this->actingAs($this->principal());

        $this->postLivewireCalls($this->renderPage('/principal/tenants'), ListTenants::class, [
            ['method' => 'mountAction', 'params' => ['suspend', [], ['table' => true, 'recordKey' => $tenant->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        // The registry row and its isolated database are untouched.
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->refresh()->status);
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);
    }

    // ------------------------------------------------------ Owner assignment

    public function test_an_owner_can_be_assigned_through_the_memberships_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['assignOwner', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.user_id' => $owner->getKey(),
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $membership = TenantMembership::query()
            ->where('user_id', $owner->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(TenantMembership::STATUS_ACTIVE, $membership->status);
        $this->assertTrue(
            $membership->roles()->where('key', RoleKey::TENANT_OWNER)->exists(),
            'Owner assignment must attach the existing tenant_owner RBAC role.',
        );
    }

    // ----------------------------------------------------- Brand association

    public function test_a_brand_can_be_associated_through_the_brands_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $brand = Brand::factory()->create();

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        // The brand manager lives on the third relation-manager tab
        // (memberships, subscriptions, brands).
        $html = $this->openRelationManagerTab($html, EditTenant::class, 2);

        $this->callRelationManager($html, BrandsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['attach', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.recordId' => $brand->getKey(),
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertDatabaseHas('tenant_brands', [
            'tenant_id' => $tenant->getKey(),
            'brand_id' => $brand->getKey(),
        ]);
    }

    // -------------------------------------------------------- Subscription

    public function test_a_tenant_can_be_subscribed_through_the_subscriptions_manager(): void
    {
        $tenant = Tenant::factory()->create();

        $moduleKey = 'demo.showcase';
        RegisteredModule::factory()->forKey($moduleKey)->enabled()->create();

        $plan = SubscriptionPlan::query()->create([
            'code' => 'klinik-starter',
            'name' => 'Klinik Starter',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => '250000.00',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 0,
            'module_keys' => [$moduleKey],
        ]);

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        // The subscriptions manager lives on the second relation-manager tab
        // (memberships, subscriptions, brands).
        $html = $this->openRelationManagerTab($html, EditTenant::class, 1);

        $this->callRelationManager($html, SubscriptionsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['subscribe', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.plan_id' => $plan->getKey(),
                'mountedActions.0.data.seats' => 5,
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->getKey(),
            'seats' => 5,
        ]);

        // Licensing routes through the existing ModuleAdministration write
        // path, so the entitlement the FeatureGate reads is installed and
        // marked subscription-backed.
        $this->assertDatabaseHas('module_entitlements', [
            'module_key' => $moduleKey,
            'scope' => ModuleScope::TENANT,
            'scope_key' => $tenant->getKey(),
            'subscription_required' => true,
        ]);
    }

    // -------------------------------------------------- Staff invitation

    public function test_a_staff_member_can_be_invited_as_an_ineligible_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = User::factory()->role(RoleKey::RECEPTIONIST)->create(['email' => 'staff@example.test']);

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['inviteStaff', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.email' => 'staff@example.test',
                'mountedActions.0.data.name' => 'Staff One',
                'mountedActions.0.data.role_keys' => [RoleKey::RECEPTIONIST],
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $membership = TenantMembership::query()
            ->where('user_id', $staff->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(TenantMembership::STATUS_INVITED, $membership->status);
        $this->assertTrue(
            $membership->roles()->where('key', RoleKey::RECEPTIONIST)->exists(),
            'An invitation must attach the assigned RBAC roles.',
        );

        // The invited state grants nothing: the Clinic dashboard stays denied.
        $this->actingAs($staff)->get('/clinic')->assertForbidden();
    }

    public function test_inviting_an_unknown_email_creates_a_clinic_staff_account(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['inviteStaff', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.email' => 'new.staff@example.test',
                'mountedActions.0.data.name' => 'New Staff',
                'mountedActions.0.data.role_keys' => [RoleKey::CONSULTANT],
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $user = User::query()->where('email', 'new.staff@example.test')->firstOrFail();

        $this->assertSame(RoleKey::CONSULTANT, $user->role);
        $this->assertTrue((bool) $user->is_active);

        $this->assertDatabaseHas('tenant_memberships', [
            'user_id' => $user->getKey(),
            'tenant_id' => $tenant->getKey(),
            'status' => TenantMembership::STATUS_INVITED,
        ]);
    }

    public function test_an_active_member_is_not_re_invited(): void
    {
        $tenant = Tenant::factory()->create();
        $member = User::factory()->role(RoleKey::TENANT_ADMIN)->create(['email' => 'member@example.test']);
        $this->giveMembership($member, $tenant, [RoleKey::TENANT_ADMIN]);

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['inviteStaff', [], ['table' => true]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.email' => 'member@example.test',
                'mountedActions.0.data.name' => 'Member',
                'mountedActions.0.data.role_keys' => [RoleKey::TENANT_ADMIN],
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        // The pre-existing active membership is left untouched.
        $this->assertDatabaseHas('tenant_memberships', [
            'user_id' => $member->getKey(),
            'tenant_id' => $tenant->getKey(),
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_an_invitation_can_be_cancelled(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = User::factory()->role(RoleKey::RECEPTIONIST)->create();
        $membership = $this->giveMembership(
            $staff,
            $tenant,
            [RoleKey::RECEPTIONIST],
            TenantMembership::STATUS_INVITED,
        );

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/tenants/'.$tenant->getKey().'/edit');

        $this->callRelationManager($html, MembershipsRelationManager::class, [
            ['method' => 'mountAction', 'params' => ['cancelInvite', [], ['table' => true, 'recordKey' => $membership->getKey()]]],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertSame(TenantMembership::STATUS_REVOKED, $membership->refresh()->status);
    }
}
