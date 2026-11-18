<?php

namespace Tests\Feature\Platform\FeatureGate;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\RoleKey;
use App\Platform\FeatureGate\Contracts\EvaluatesModuleAccess;
use App\Platform\FeatureGate\Contracts\GrantsModuleEntitlements;
use App\Platform\FeatureGate\Contracts\VerifiesModuleSubscriptions;
use App\Platform\FeatureGate\EntitlementGrant;
use App\Platform\FeatureGate\Exceptions\ModuleAccessDeniedException;
use App\Platform\FeatureGate\FeatureGateReason;
use App\Platform\FeatureGate\ModuleAccessRequest;
use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleManifest;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Subscriptions\SubscriptionLicensing;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1B-4 server-side FeatureGate — DENY BY DEFAULT.
 *
 * The gate answers one question with one formula:
 *
 *     installed && healthy && entitled && subscribed && authorized && allowed_panel
 *
 * Every negative case from the Phase 1B-4 brief is exercised here. The single
 * allowed path is only ever resolved through an explicit, synthetic,
 * test-scoped entitlement (+ subscription) provider, never by faking a grant in
 * production code.
 */
class FeatureGateTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    private const MODULE = 'demo.showcase';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    // ---------------------------------------------------------------- Helpers

    private function gate(): EvaluatesModuleAccess
    {
        return $this->app->make(EvaluatesModuleAccess::class);
    }

    private function request(
        ?User $user,
        ?AccessContext $context = null,
        string $panelId = PanelAccessPolicy::PANEL_CLINIC,
        ?string $brandId = null,
        string $moduleKey = self::MODULE,
    ): ModuleAccessRequest {
        return new ModuleAccessRequest(
            moduleKey: $moduleKey,
            panelId: $panelId,
            user: $user,
            context: $context,
            brandId: $brandId,
        );
    }

    /**
     * A synthetic Clinic identity with one active membership carrying the
     * `tenant_owner` role (which holds `tenant.profile.manage`).
     */
    private function clinicUser(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    /**
     * Bind explicit, test-scoped entitlement and subscription providers, then
     * rebuild the gate so it consumes them.
     */
    private function bindSynthetic(
        GrantsModuleEntitlements $entitlements,
        VerifiesModuleSubscriptions $subscriptions,
    ): void {
        $this->app->instance(GrantsModuleEntitlements::class, $entitlements);
        $this->app->instance(VerifiesModuleSubscriptions::class, $subscriptions);
        $this->app->forgetInstance(EvaluatesModuleAccess::class);
    }

    /**
     * Create the explicit, trusted activation a module now requires: a platform
     * catalog row plus a per-scope installation row. Under Phase 1B-4R a module
     * with no such records fails closed, so every allowed path must establish
     * this state.
     */
    private function installModule(string $key, string $tenantId): void
    {
        RegisteredModule::factory()->forKey($key)->enabled()->create();

        ModuleInstallation::factory()
            ->forModule($key)
            ->forScope(ModuleScope::TENANT, $tenantId)
            ->enabled()
            ->create();
    }

    /**
     * A live licence for one tenant: an active plan that licenses the given
     * module keys, bound to an active subscription whose reference the
     * FeatureGate verifies.
     *
     * @param  list<string>  $moduleKeys
     */
    private function licensedSubscription(string $tenantId, array $moduleKeys): TenantSubscription
    {
        $plan = SubscriptionPlan::query()->create([
            'code' => 'plan-'.uniqid(),
            'name' => 'Licence Plan',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'module_keys' => $moduleKeys,
        ]);

        return TenantSubscription::query()->create([
            'tenant_id' => $tenantId,
            'plan_id' => $plan->getKey(),
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_end' => now()->addMonth(),
        ]);
    }

    // ----------------------------------------------------- Discovery / module

    public function test_an_undeclared_module_is_denied(): void
    {
        $result = $this->gate()->evaluate(
            $this->request(User::factory()->role(RoleKey::TENANT_OWNER)->create(), moduleKey: 'nope.nope'),
        );

        $this->assertTrue($result->denied());
        $this->assertSame(FeatureGateReason::UNKNOWN_MODULE, $result->reason);
    }

    public function test_a_module_with_an_incompatible_minimum_platform_is_denied(): void
    {
        $this->app->make(RegistersModules::class)->register(new ModuleManifest(
            key: 'demo.future',
            name: 'Future Demo',
            version: '1.0.0',
            minPlatformVersion: '99.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
        ));

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $result = $this->gate()->evaluate(
            $this->request($user, AccessContext::forTenant($tenant->getKey()), moduleKey: 'demo.future'),
        );

        $this->assertSame(FeatureGateReason::INCOMPATIBLE_PLATFORM, $result->reason);
    }

    // -------------------------------------------------------- Panel isolation

    public function test_a_module_cannot_be_reached_through_a_panel_it_does_not_allow(): void
    {
        $result = $this->gate()->evaluate(
            $this->request(
                User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create(),
                panelId: PanelAccessPolicy::PANEL_PATIENT,
            ),
        );

        $this->assertSame(FeatureGateReason::PANEL_NOT_ALLOWED, $result->reason);
    }

    public function test_a_principal_identity_cannot_reach_a_clinic_only_module(): void
    {
        $principal = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();

        // Their own panel: the module is not declared for the Principal panel.
        $result = $this->gate()->evaluate(
            $this->request($principal, panelId: PanelAccessPolicy::PANEL_PRINCIPAL),
        );

        $this->assertSame(FeatureGateReason::PANEL_NOT_ALLOWED, $result->reason);

        // Even invoking it against the Clinic panel is refused: a Principal role
        // may not reach the Clinic panel at all.
        $result = $this->gate()->evaluate(
            $this->request($principal, panelId: PanelAccessPolicy::PANEL_CLINIC),
        );

        $this->assertSame(FeatureGateReason::UNAUTHORIZED, $result->reason);
    }

    public function test_a_patient_identity_cannot_reach_a_staff_module(): void
    {
        $result = $this->gate()->evaluate(
            $this->request(
                User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create(),
                panelId: PanelAccessPolicy::PANEL_PATIENT,
            ),
        );

        $this->assertSame(FeatureGateReason::PANEL_NOT_ALLOWED, $result->reason);
    }

    // ----------------------------------------------------------- Authorization

    public function test_a_missing_identity_is_denied(): void
    {
        $result = $this->gate()->evaluate($this->request(null));

        $this->assertSame(FeatureGateReason::UNAUTHORIZED, $result->reason);
    }

    public function test_a_suspended_identity_is_denied(): void
    {
        $result = $this->gate()->evaluate(
            $this->request(User::factory()->role(RoleKey::TENANT_OWNER)->suspended()->create()),
        );

        $this->assertSame(FeatureGateReason::UNAUTHORIZED, $result->reason);
    }

    public function test_an_identity_without_a_membership_in_the_scoped_tenant_is_denied(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        // Member of A only, but the verified context claims B.
        $user = $this->clinicUser($tenantA);

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenantB->getKey())));

        $this->assertSame(FeatureGateReason::UNAUTHORIZED, $result->reason);
    }

    public function test_a_module_requiring_an_unknown_permission_is_denied(): void
    {
        $this->app->make(RegistersModules::class)->register(new ModuleManifest(
            key: 'demo.unknown-permission',
            name: 'Unknown Permission Demo',
            version: '1.0.0',
            minPlatformVersion: '3.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
            requiredPermissions: ['not.a.real.permission'],
        ));

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $result = $this->gate()->evaluate(
            $this->request($user, AccessContext::forTenant($tenant->getKey()), moduleKey: 'demo.unknown-permission'),
        );

        $this->assertSame(FeatureGateReason::UNKNOWN_PERMISSION, $result->reason);
    }

    public function test_a_verified_scope_is_required(): void
    {
        // One eligible membership (so authorization passes) but no verified
        // context was supplied to the request: the gate refuses to invent one.
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $result = $this->gate()->evaluate($this->request($user, null));

        $this->assertSame(FeatureGateReason::NO_VERIFIED_SCOPE, $result->reason);
    }

    // --------------------------------------------------------- State (installed)

    public function test_a_module_that_is_not_installed_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        RegisteredModule::factory()->forKey(self::MODULE)->create(); // not installed

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::NOT_INSTALLED, $result->reason);
    }

    public function test_a_disabled_module_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        RegisteredModule::factory()->forKey(self::MODULE)->disabled()->create();
        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, $tenant->getKey())
            ->enabled()
            ->create();

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::DISABLED, $result->reason);
    }

    public function test_an_unhealthy_module_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        RegisteredModule::factory()->forKey(self::MODULE)->unhealthy()->create();
        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, $tenant->getKey())
            ->enabled()
            ->create();

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::UNHEALTHY, $result->reason);
    }

    public function test_a_module_with_no_explicit_catalog_or_installation_record_fails_closed(): void
    {
        // Neither a catalog row nor a per-scope installation row exists: the
        // gate must not infer installed / enabled / healthy from the manifest.
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::NOT_INSTALLED, $result->reason);
    }

    public function test_a_module_with_a_catalog_row_but_no_per_scope_installation_fails_closed(): void
    {
        // The platform master switch is on, but this scope was never installed.
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::NOT_INSTALLED, $result->reason);
    }

    public function test_a_per_scope_installation_can_disable_a_module_without_affecting_others(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, $tenantA->getKey())
            ->disabled()
            ->create();

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, $tenantB->getKey())
            ->enabled()
            ->create();

        $userA = $this->clinicUser($tenantA);
        $userB = $this->clinicUser($tenantB);

        ModuleEntitlement::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenantA->getKey())->granted()->create();
        ModuleEntitlement::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenantB->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::DISABLED,
            $this->gate()->evaluate($this->request($userA, AccessContext::forTenant($tenantA->getKey())))->reason,
        );

        $this->assertTrue(
            $this->gate()->evaluate($this->request($userB, AccessContext::forTenant($tenantB->getKey())))->allowed,
        );
    }

    // ------------------------------------------------------------ Entitlement

    public function test_a_module_without_an_entitlement_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule(self::MODULE, $tenant->getKey());

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::NOT_ENTITLED, $result->reason);
    }

    public function test_a_revoked_entitlement_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule(self::MODULE, $tenant->getKey());

        ModuleEntitlement::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenant->getKey())->revoked()->create();

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::NOT_ENTITLED, $result->reason);
    }

    public function test_an_expired_entitlement_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule(self::MODULE, $tenant->getKey());

        ModuleEntitlement::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenant->getKey())->expired()->create();

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::NOT_ENTITLED, $result->reason);
    }

    public function test_an_entitlement_for_tenant_a_never_authorizes_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();

        ModuleInstallation::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenantA->getKey())->enabled()->create();
        ModuleInstallation::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenantB->getKey())->enabled()->create();

        ModuleEntitlement::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenantA->getKey())->granted()->create();

        $userA = $this->clinicUser($tenantA);
        $userB = $this->clinicUser($tenantB);

        $this->assertTrue(
            $this->gate()->evaluate($this->request($userA, AccessContext::forTenant($tenantA->getKey())))->allowed,
        );

        // Tenant B holds no grant of its own: the Tenant A grant must not leak.
        $this->assertSame(
            FeatureGateReason::NOT_ENTITLED,
            $this->gate()->evaluate($this->request($userB, AccessContext::forTenant($tenantB->getKey())))->reason,
        );
    }

    // ----------------------------------------------------------- Subscription

    public function test_the_subscription_provider_fails_closed_without_a_matching_licence(): void
    {
        $this->app->forgetInstance(VerifiesModuleSubscriptions::class);

        $this->assertFalse(
            $this->app->make(VerifiesModuleSubscriptions::class)
                ->isActive(self::MODULE, new ModuleScopeKey(ModuleScope::TENANT, 'anything'), 'anything'),
        );
    }

    public function test_a_module_requiring_a_subscription_is_denied_without_a_matching_licence(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule(self::MODULE, $tenant->getKey());

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, $tenant->getKey())
            ->granted()
            ->requiringSubscription('synthetic-subscription')
            ->create();

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertSame(FeatureGateReason::NO_SUBSCRIPTION, $result->reason);
    }

    public function test_a_licensed_module_is_allowed_and_fails_closed_once_cancelled(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule(self::MODULE, $tenant->getKey());

        $subscription = $this->licensedSubscription($tenant->getKey(), [self::MODULE]);

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, $tenant->getKey())
            ->granted()
            ->requiringSubscription($subscription->reference)
            ->create();

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertTrue($result->allowed);
        $this->assertSame(FeatureGateReason::ALLOWED, $result->reason);

        // Cancelling the licence fails the identical request closed immediately.
        app(SubscriptionLicensing::class)->cancel($subscription);

        $this->assertSame(
            FeatureGateReason::NO_SUBSCRIPTION,
            $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())))->reason,
        );
    }

    public function test_a_subscription_licence_does_not_leak_to_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userB = $this->clinicUser($tenantB);

        $this->installModule(self::MODULE, $tenantB->getKey());

        // The subscription belongs to Tenant A.
        $subscription = $this->licensedSubscription($tenantA->getKey(), [self::MODULE]);

        // Tenant B records a reference that names Tenant A's subscription.
        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, $tenantB->getKey())
            ->granted()
            ->requiringSubscription($subscription->reference)
            ->create();

        $this->assertSame(
            FeatureGateReason::NO_SUBSCRIPTION,
            $this->gate()->evaluate($this->request($userB, AccessContext::forTenant($tenantB->getKey())))->reason,
        );
    }

    // --------------------------------------------------------- Branch scoping

    public function test_a_branch_scoped_grant_is_denied_until_trusted_branch_context_exists(): void
    {
        $this->app->make(RegistersModules::class)->register(new ModuleManifest(
            key: 'demo.branch',
            name: 'Branch Demo',
            version: '1.0.0',
            minPlatformVersion: '3.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
            scopeCompatibility: [ModuleScope::BRANCH],
        ));

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        // A perfectly valid branch-scoped installation and entitlement exist —
        // and are still ignored, because no branch candidate is ever produced.
        RegisteredModule::factory()->forKey('demo.branch')->enabled()->create();
        ModuleInstallation::factory()->forModule('demo.branch')->forScope(ModuleScope::BRANCH, 'branch-1')->enabled()->create();
        ModuleEntitlement::factory()->forModule('demo.branch')->forScope(ModuleScope::BRANCH, 'branch-1')->granted()->create();

        // Neither a plausible nor a fabricated branch id is proof of
        // authorization: branch scope stays denied until Phase 2.
        foreach ([null, 'branch-1', 'fabricated-branch'] as $branchId) {
            $result = $this->gate()->evaluate(
                $this->request(
                    $user,
                    AccessContext::forTenant($tenant->getKey(), $branchId),
                    moduleKey: 'demo.branch',
                ),
            );

            $this->assertSame(FeatureGateReason::NO_VERIFIED_SCOPE, $result->reason);
        }
    }

    // ------------------------------------------------- Verified brand scoping (Task 1)

    private function registerBrandModule(string $key = 'demo.brand'): void
    {
        $this->app->make(RegistersModules::class)->register(new ModuleManifest(
            key: $key,
            name: 'Brand Demo',
            version: '1.0.0',
            minPlatformVersion: '3.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
            scopeCompatibility: [ModuleScope::BRAND],
        ));
    }

    private function installBrandModule(string $key, string $brandId): void
    {
        RegisteredModule::factory()->forKey($key)->enabled()->create();
        ModuleInstallation::factory()->forModule($key)->forScope(ModuleScope::BRAND, $brandId)->enabled()->create();
        ModuleEntitlement::factory()->forModule($key)->forScope(ModuleScope::BRAND, $brandId)->granted()->create();
    }

    public function test_a_brand_scoped_module_is_allowed_only_with_a_verified_brand_relationship(): void
    {
        $this->registerBrandModule();

        $tenant = Tenant::factory()->create();
        $brand = Brand::factory()->create();
        $tenant->brands()->attach($brand->getKey(), ['is_primary' => true]);

        $user = $this->clinicUser($tenant);

        $this->installBrandModule('demo.brand', $brand->getKey());

        // A brand id alone is not authority: no verified relationship => denied.
        $this->assertSame(
            FeatureGateReason::NO_VERIFIED_SCOPE,
            $this->gate()->evaluate(
                $this->request($user, AccessContext::forTenant($tenant->getKey()), moduleKey: 'demo.brand'),
            )->reason,
        );

        // With the explicitly verified user -> tenant -> brand chain: allowed.
        $result = $this->gate()->evaluate(
            $this->request(
                $user,
                AccessContext::forTenant($tenant->getKey()),
                brandId: $brand->getKey(),
                moduleKey: 'demo.brand',
            ),
        );

        $this->assertTrue($result->allowed);
        $this->assertSame(ModuleScope::BRAND, $result->scope?->scope);
        $this->assertSame($brand->getKey(), $result->scope?->scopeKey);
    }

    public function test_a_foreign_brand_id_is_never_treated_as_authorization(): void
    {
        $this->registerBrandModule();

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $foreignBrand = Brand::factory()->create();
        $tenantB->brands()->attach($foreignBrand->getKey(), ['is_primary' => true]);

        $user = $this->clinicUser($tenantA);

        // Even a genuine grant for the foreign brand must not authorize it.
        $this->installBrandModule('demo.brand', $foreignBrand->getKey());

        $result = $this->gate()->evaluate(
            $this->request(
                $user,
                AccessContext::forTenant($tenantA->getKey()),
                brandId: $foreignBrand->getKey(),
                moduleKey: 'demo.brand',
            ),
        );

        $this->assertTrue($result->denied());
        $this->assertSame(FeatureGateReason::NO_VERIFIED_SCOPE, $result->reason);
    }

    public function test_a_fabricated_brand_id_is_never_treated_as_authorization(): void
    {
        $this->registerBrandModule();

        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $result = $this->gate()->evaluate(
            $this->request(
                $user,
                AccessContext::forTenant($tenant->getKey()),
                brandId: 'fabricated-brand-does-not-exist',
                moduleKey: 'demo.brand',
            ),
        );

        $this->assertSame(FeatureGateReason::NO_VERIFIED_SCOPE, $result->reason);
    }

    public function test_a_mismatched_tenant_brand_relationship_is_denied(): void
    {
        $this->registerBrandModule();

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $brand = Brand::factory()->create();
        $tenantA->brands()->attach($brand->getKey(), ['is_primary' => true]);

        // The identity belongs to Tenant B, but claims a brand owned by A.
        $user = $this->clinicUser($tenantB);

        $this->installBrandModule('demo.brand', $brand->getKey());

        $result = $this->gate()->evaluate(
            $this->request(
                $user,
                AccessContext::forTenant($tenantB->getKey()),
                brandId: $brand->getKey(),
                moduleKey: 'demo.brand',
            ),
        );

        $this->assertSame(FeatureGateReason::NO_VERIFIED_SCOPE, $result->reason);
    }

    public function test_a_suspended_brand_is_denied(): void
    {
        $this->registerBrandModule();

        $tenant = Tenant::factory()->create();
        $brand = Brand::factory()->suspended()->create();
        $tenant->brands()->attach($brand->getKey(), ['is_primary' => true]);

        $user = $this->clinicUser($tenant);

        $this->installBrandModule('demo.brand', $brand->getKey());

        $result = $this->gate()->evaluate(
            $this->request(
                $user,
                AccessContext::forTenant($tenant->getKey()),
                brandId: $brand->getKey(),
                moduleKey: 'demo.brand',
            ),
        );

        $this->assertSame(FeatureGateReason::NO_VERIFIED_SCOPE, $result->reason);
    }

    // -------------------------------------------------------- Membership churn

    public function test_a_revoked_membership_is_denied_immediately(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $membership = TenantMembership::query()->where('user_id', $user->getKey())->firstOrFail();

        $this->installModule(self::MODULE, $tenant->getKey());

        ModuleEntitlement::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertTrue(
            $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())))->allowed,
        );

        $membership->update(['status' => TenantMembership::STATUS_REVOKED]);

        $this->assertSame(
            FeatureGateReason::UNAUTHORIZED,
            $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())))->reason,
        );
    }

    // --------------------------------------------------------------- Allowed

    public function test_the_allowed_path_resolves_only_with_a_verified_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->installModule(self::MODULE, $tenant->getKey());

        ModuleEntitlement::factory()->forModule(self::MODULE)->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $result = $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())));

        $this->assertTrue($result->allowed);
        $this->assertSame(FeatureGateReason::ALLOWED, $result->reason);
        $this->assertSame(ModuleScope::TENANT, $result->scope?->scope);
        $this->assertSame($tenant->getKey(), $result->scope?->scopeKey);
    }

    public function test_an_allowed_path_can_resolve_through_synthetic_test_scoped_providers(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $entitlements = new class($tenant->getKey()) implements GrantsModuleEntitlements
        {
            public bool $granted = true;

            public function __construct(private readonly string $scopeKey) {}

            public function entitlementFor(string $moduleKey, ModuleScopeKey $scope): ?EntitlementGrant
            {
                if ($moduleKey !== 'demo.showcase' || $scope->scope !== ModuleScope::TENANT || $scope->scopeKey !== $this->scopeKey) {
                    return null;
                }

                return new EntitlementGrant(granted: $this->granted, subscriptionRequired: true, subscriptionReference: 'synthetic-sub');
            }
        };

        $subscriptions = new class implements VerifiesModuleSubscriptions
        {
            public bool $active = true;

            public function isActive(string $moduleKey, ModuleScopeKey $scope, ?string $reference): bool
            {
                return $this->active && $moduleKey === 'demo.showcase' && $reference === 'synthetic-sub';
            }
        };

        $this->bindSynthetic($entitlements, $subscriptions);

        $this->installModule(self::MODULE, $tenant->getKey());

        $this->assertTrue(
            $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())))->allowed,
        );

        // Flip the synthetic providers off: the same request now fails closed.
        $entitlements->granted = false;
        $this->assertSame(
            FeatureGateReason::NOT_ENTITLED,
            $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())))->reason,
        );

        $entitlements->granted = true;
        $subscriptions->active = false;
        $this->assertSame(
            FeatureGateReason::NO_SUBSCRIPTION,
            $this->gate()->evaluate($this->request($user, AccessContext::forTenant($tenant->getKey())))->reason,
        );
    }

    public function test_authorize_throws_a_typed_exception_on_denial(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->expectException(ModuleAccessDeniedException::class);

        $this->gate()->authorize($this->request($user));
    }

    // ------------------------------------------------------------ Dependencies (Task 3)

    /**
     * @param  list<string>  $requires
     */
    private function registerModule(string $key, array $requires = []): void
    {
        $this->app->make(RegistersModules::class)->register(new ModuleManifest(
            key: $key,
            name: $key,
            version: '1.0.0',
            minPlatformVersion: '3.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
            requiredDependencies: $requires,
        ));
    }

    private function reasonFor(User $user, string $tenantId, string $moduleKey): string
    {
        return $this->gate()
            ->evaluate($this->request($user, AccessContext::forTenant($tenantId), moduleKey: $moduleKey))
            ->reason;
    }

    public function test_a_module_with_a_satisfied_platform_dependency_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->registerModule('demo.dependency', ['platform.tenancy']);
        $this->installModule('demo.dependency', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependency')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertTrue(
            $this->gate()->evaluate(
                $this->request($user, AccessContext::forTenant($tenant->getKey()), moduleKey: 'demo.dependency'),
            )->allowed,
        );
    }

    public function test_a_module_with_an_installed_business_dependency_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->registerModule('demo.base');
        $this->installModule('demo.base', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.base')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->registerModule('demo.dependent', ['demo.base']);
        $this->installModule('demo.dependent', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependent')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertTrue(
            $this->gate()->evaluate(
                $this->request($user, AccessContext::forTenant($tenant->getKey()), moduleKey: 'demo.dependent'),
            )->allowed,
        );
    }

    public function test_a_module_with_an_unknown_platform_dependency_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->registerModule('demo.dependency', ['platform.nonexistent']);
        $this->installModule('demo.dependency', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependency')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::UNSATISFIED_PLATFORM_DEPENDENCY,
            $this->reasonFor($user, $tenant->getKey(), 'demo.dependency'),
        );
    }

    public function test_a_module_with_a_missing_dependency_module_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        // The dependency is named but was never discovered on the platform.
        $this->registerModule('demo.dependent', ['demo.absent']);
        $this->installModule('demo.dependent', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependent')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::MISSING_DEPENDENCY,
            $this->reasonFor($user, $tenant->getKey(), 'demo.dependent'),
        );
    }

    public function test_a_module_with_a_not_installed_dependency_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        // Discovered, but never explicitly installed at this scope.
        $this->registerModule('demo.base');

        $this->registerModule('demo.dependent', ['demo.base']);
        $this->installModule('demo.dependent', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependent')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::MISSING_DEPENDENCY,
            $this->reasonFor($user, $tenant->getKey(), 'demo.dependent'),
        );
    }

    public function test_a_module_with_a_disabled_dependency_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->registerModule('demo.base');
        RegisteredModule::factory()->forKey('demo.base')->disabled()->create();
        ModuleInstallation::factory()->forModule('demo.base')->forScope(ModuleScope::TENANT, $tenant->getKey())->enabled()->create();

        $this->registerModule('demo.dependent', ['demo.base']);
        $this->installModule('demo.dependent', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependent')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::DISABLED_DEPENDENCY,
            $this->reasonFor($user, $tenant->getKey(), 'demo.dependent'),
        );
    }

    public function test_a_module_with_an_unhealthy_dependency_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->registerModule('demo.base');
        RegisteredModule::factory()->forKey('demo.base')->unhealthy()->create();
        ModuleInstallation::factory()->forModule('demo.base')->forScope(ModuleScope::TENANT, $tenant->getKey())->enabled()->create();

        $this->registerModule('demo.dependent', ['demo.base']);
        $this->installModule('demo.dependent', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependent')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::UNHEALTHY_DEPENDENCY,
            $this->reasonFor($user, $tenant->getKey(), 'demo.dependent'),
        );
    }

    public function test_a_module_with_an_incompatible_dependency_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->app->make(RegistersModules::class)->register(new ModuleManifest(
            key: 'demo.base',
            name: 'Future Base',
            version: '1.0.0',
            minPlatformVersion: '99.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
        ));

        $this->registerModule('demo.dependent', ['demo.base']);
        $this->installModule('demo.dependent', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependent')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::INCOMPATIBLE_DEPENDENCY,
            $this->reasonFor($user, $tenant->getKey(), 'demo.dependent'),
        );
    }

    public function test_a_circular_dependency_graph_is_denied_without_looping(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        $this->registerModule('demo.alpha', ['demo.beta']);
        $this->registerModule('demo.beta', ['demo.alpha']);

        $this->installModule('demo.alpha', $tenant->getKey());
        $this->installModule('demo.beta', $tenant->getKey());

        ModuleEntitlement::factory()->forModule('demo.alpha')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::CIRCULAR_DEPENDENCY,
            $this->reasonFor($user, $tenant->getKey(), 'demo.alpha'),
        );
    }

    public function test_a_platform_dependency_is_not_resolved_as_a_business_module(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        // A module deliberately registered under a `platform.*` key is left
        // *uninstalled*: were the dependency resolved as a business module it
        // would fail closed. Platform dependencies are answered from the
        // platform's own capability list only, so access is still allowed.
        $this->registerModule('platform.tenancy');

        $this->registerModule('demo.dependent', ['platform.tenancy']);
        $this->installModule('demo.dependent', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependent')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertTrue(
            $this->gate()->evaluate(
                $this->request($user, AccessContext::forTenant($tenant->getKey()), moduleKey: 'demo.dependent'),
            )->allowed,
        );
    }

    public function test_a_platform_dependency_not_listed_by_the_platform_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->clinicUser($tenant);

        // The platform capability list is the sole authority for platform deps.
        config()->set('lba.platform.capabilities', ['platform.identity']);

        $this->registerModule('demo.dependent', ['platform.tenancy']);
        $this->installModule('demo.dependent', $tenant->getKey());
        ModuleEntitlement::factory()->forModule('demo.dependent')->forScope(ModuleScope::TENANT, $tenant->getKey())->granted()->create();

        $this->assertSame(
            FeatureGateReason::UNSATISFIED_PLATFORM_DEPENDENCY,
            $this->reasonFor($user, $tenant->getKey(), 'demo.dependent'),
        );
    }
}
