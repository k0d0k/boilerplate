<?php

namespace Tests\Feature\Platform\Subscriptions;

use App\Models\Tenant;
use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\FeatureGate\Providers\DatabaseModuleSubscriptions;
use App\Platform\ModuleRegistry\EntitlementStatus;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\ModuleRegistry\ModuleStatus;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Subscriptions\SubscriptionLicensing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WS4 — subscription plans, tenant licensing and the subscription verifier.
 *
 * Proves that licensing a tenant installs + subscription-backs the licensed
 * module entitlements and that {@see DatabaseModuleSubscriptions} honours a
 * live licence — and fails closed on every ambiguity (missing/foreign/expired
 * licence, unlicensed module, non-tenant scope).
 */
class SubscriptionLicensingTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'demo.showcase';

    private function plan(array $attributes = []): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create(array_merge([
            'code' => 'plan-'.uniqid(),
            'name' => 'Klinik Plan',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => '250000.00',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 0,
            'module_keys' => [self::MODULE],
        ], $attributes));
    }

    private function installableModule(string $key = self::MODULE): void
    {
        RegisteredModule::factory()->forKey($key)->enabled()->create();
    }

    // ------------------------------------------------------------- Licensing

    public function test_subscribing_a_tenant_licenses_the_plan_modules(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installableModule();

        $subscription = app(SubscriptionLicensing::class)->subscribe($tenant, $this->plan());

        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->reference);
        $this->assertTrue($subscription->isActive());

        $entitlement = ModuleEntitlement::query()
            ->where('module_key', self::MODULE)
            ->where('scope', ModuleScope::TENANT)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(EntitlementStatus::GRANTED, $entitlement->status);
        $this->assertTrue($entitlement->subscription_required);
        $this->assertSame($subscription->reference, $entitlement->subscription_reference);

        $installation = ModuleInstallation::query()
            ->where('module_key', self::MODULE)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(ModuleStatus::ENABLED, $installation->status);
    }

    public function test_a_trial_plan_starts_the_subscription_in_trial(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installableModule();

        $subscription = app(SubscriptionLicensing::class)->subscribe($tenant, $this->plan(['trial_days' => 14]));

        $this->assertSame(TenantSubscription::STATUS_TRIALING, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertTrue($subscription->isActive());
    }

    public function test_cancelling_a_subscription_makes_it_inactive(): void
    {
        $tenant = Tenant::factory()->create();
        $this->installableModule();

        $subscription = app(SubscriptionLicensing::class)->subscribe($tenant, $this->plan());

        app(SubscriptionLicensing::class)->cancel($subscription);

        $subscription->refresh();

        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->status);
        $this->assertFalse($subscription->isActive());
    }

    // ------------------------------------------------------------ Verifier

    public function test_the_verifier_accepts_an_active_licensed_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $subscription = app(SubscriptionLicensing::class)->subscribe($tenant, $this->plan());

        $this->assertTrue((new DatabaseModuleSubscriptions)->isActive(
            self::MODULE,
            new ModuleScopeKey(ModuleScope::TENANT, $tenant->getKey()),
            $subscription->reference,
        ));
    }

    public function test_the_verifier_rejects_a_missing_reference(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertFalse((new DatabaseModuleSubscriptions)->isActive(
            self::MODULE,
            new ModuleScopeKey(ModuleScope::TENANT, $tenant->getKey()),
            null,
        ));
    }

    public function test_the_verifier_rejects_a_non_tenant_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $subscription = app(SubscriptionLicensing::class)->subscribe($tenant, $this->plan());

        $this->assertFalse((new DatabaseModuleSubscriptions)->isActive(
            self::MODULE,
            new ModuleScopeKey(ModuleScope::BRAND, 'brand-1'),
            $subscription->reference,
        ));
    }

    public function test_the_verifier_rejects_a_reference_that_belongs_to_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $subscription = app(SubscriptionLicensing::class)->subscribe($tenantA, $this->plan());

        $this->assertFalse((new DatabaseModuleSubscriptions)->isActive(
            self::MODULE,
            new ModuleScopeKey(ModuleScope::TENANT, $tenantB->getKey()),
            $subscription->reference,
        ));
    }

    public function test_the_verifier_rejects_a_module_the_plan_does_not_license(): void
    {
        $tenant = Tenant::factory()->create();
        $subscription = app(SubscriptionLicensing::class)->subscribe($tenant, $this->plan());

        $this->assertFalse((new DatabaseModuleSubscriptions)->isActive(
            'unlicensed.module',
            new ModuleScopeKey(ModuleScope::TENANT, $tenant->getKey()),
            $subscription->reference,
        ));
    }

    public function test_the_verifier_rejects_an_expired_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $subscription = app(SubscriptionLicensing::class)->subscribe($tenant, $this->plan());

        $subscription->update(['current_period_end' => now()->subDay()]);

        $this->assertFalse((new DatabaseModuleSubscriptions)->isActive(
            self::MODULE,
            new ModuleScopeKey(ModuleScope::TENANT, $tenant->getKey()),
            $subscription->reference,
        ));
    }

    public function test_a_wildcard_plan_licenses_every_module(): void
    {
        $tenant = Tenant::factory()->create();
        $subscription = app(SubscriptionLicensing::class)->subscribe(
            $tenant,
            $this->plan(['module_keys' => [SubscriptionPlan::MODULE_WILDCARD]]),
        );

        $this->assertTrue((new DatabaseModuleSubscriptions)->isActive(
            'anything.at.all',
            new ModuleScopeKey(ModuleScope::TENANT, $tenant->getKey()),
            $subscription->reference,
        ));
    }
}
