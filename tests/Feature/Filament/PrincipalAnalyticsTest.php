<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Platform\Access\RoleKey;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\Analytics\PlatformAnalytics;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\SubscriptionLicensing;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * WS4.3 — Principal business analytics & revenue reporting.
 *
 * The analytics surface reports REAL figures computed from CENTRAL tables only.
 * These tests prove it (a) renders for an authorised principal and fails closed
 * without `platform.analytics.view`, and (b) reports live subscriptions, keeps
 * IDR and MYR strictly separate, normalises yearly prices to a month and never
 * surfaces anything clinical or per-patient.
 */
class PrincipalAnalyticsTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    private function plan(
        string $currency,
        string $price,
        string $interval = SubscriptionPlan::INTERVAL_MONTHLY,
    ): SubscriptionPlan {
        return SubscriptionPlan::query()->create([
            'code' => 'plan-'.uniqid(),
            'name' => 'Klinik '.$currency.' Plan',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => $price,
            'currency' => $currency,
            'billing_interval' => $interval,
            'trial_days' => 0,
            'module_keys' => [],
        ]);
    }

    private function subscribe(Tenant $tenant, SubscriptionPlan $plan, int $seats = 1): void
    {
        app(SubscriptionLicensing::class)->subscribe($tenant, $plan, $seats);
    }

    // ------------------------------------------------------- Access control

    public function test_the_analytics_page_renders_live_figures_for_an_authorized_principal(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Analitik']);
        $this->subscribe($tenant, $this->plan('IDR', '250000.00'));

        $this->actingAs($this->principal())
            ->get('/principal/analytics')
            ->assertOk()
            ->assertSee('Analitik Bisnis')
            ->assertSee('Klinik IDR Plan');
    }

    public function test_the_analytics_page_is_forbidden_without_analytics_view(): void
    {
        // brand_manager reaches the Principal panel but holds neither
        // `platform.analytics.view` nor any finance permission.
        $brandManager = $this->principal(RoleKey::BRAND_MANAGER);

        $this->actingAs($brandManager)->get('/principal/analytics')->assertForbidden();
    }

    // ---------------------------------------------------- Revenue reporting

    public function test_revenue_by_plan_reflects_live_subscriptions(): void
    {
        $tenant = Tenant::factory()->create();
        $this->subscribe($tenant, $this->plan('IDR', '250000.00'), 3);

        $rows = app(PlatformAnalytics::class)->revenueByPlan();

        $this->assertCount(1, $rows);
        $this->assertSame('Klinik IDR Plan', $rows[0]['plan']);
        $this->assertSame('IDR', $rows[0]['currency']);
        $this->assertSame(1, $rows[0]['subscriptions']);
        $this->assertSame(3, $rows[0]['seats']);
        $this->assertSame(250000.0, $rows[0]['mrr']);
    }

    public function test_monthly_recurring_revenue_keeps_currencies_separate_and_normalises_yearly_prices(): void
    {
        $tenant = Tenant::factory()->create();
        $this->subscribe($tenant, $this->plan('IDR', '300000.00'));
        $this->subscribe($tenant, $this->plan('MYR', '600.00', SubscriptionPlan::INTERVAL_YEARLY));

        $mrr = app(PlatformAnalytics::class)->monthlyRecurringRevenue();

        // Currencies are never summed across one another; the yearly MYR price
        // is normalised to a month (600 / 12 = 50).
        $this->assertSame(['IDR' => 300000.0, 'MYR' => 50.0], $mrr);
    }

    public function test_a_cancelled_subscription_drops_out_of_revenue(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = $this->plan('IDR', '250000.00');

        $subscription = app(SubscriptionLicensing::class)->subscribe($tenant, $plan);
        app(SubscriptionLicensing::class)->cancel($subscription);

        $this->assertSame([], app(PlatformAnalytics::class)->monthlyRecurringRevenue());
    }

    // ---------------------------------------------------- Governance totals

    public function test_partner_figures_reflect_the_tenant_registry(): void
    {
        Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);
        Tenant::factory()->create(['status' => Tenant::STATUS_SUSPENDED]);

        $this->assertSame(
            ['total' => 2, 'active' => 1, 'suspended' => 1],
            app(PlatformAnalytics::class)->partners(),
        );
    }

    public function test_credit_figures_are_non_identifying_integer_totals(): void
    {
        $tenant = Tenant::factory()->create();

        app(AiWalletService::class)->grant(
            (string) $tenant->getKey(),
            500,
            'seed',
            'seed-'.Str::ulid()->toBase32(),
        );

        $credits = app(PlatformAnalytics::class)->aiCredits();

        $this->assertSame(['available' => 500, 'reserved' => 0, 'consumed' => 0], $credits);
    }
}
