<?php

namespace Tests\Feature;

use App\Models\PaymentGateway;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Models\TenantAiWallet;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * Phase 5 — TailAdmin Principal governance surfaces.
 *
 * Four platform-governance screens that previously existed only as interim
 * Filament screens are now served inside the TailAdmin shell:
 *  - Paket Langganan     (principal.tailadmin.subscriptions.*)
 *  - Penggunaan & Token AI (principal.tailadmin.ai-credits.*)
 *  - Hak Akses Fitur     (principal.tailadmin.entitlements.*)
 *  - Penyedia Pembayaran (principal.tailadmin.payment-providers.*)
 *
 * Every route re-derives its permission server side, so access is asserted for
 * a fully-privileged principal, the two least-privilege principal roles, a
 * tenant-bound identity and a guest.
 */
class PrincipalGovernanceSurfacesTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    /** @var list<string> */
    private const INDEX_ROUTES = [
        'principal.tailadmin.subscriptions.index',
        'principal.tailadmin.ai-credits.index',
        'principal.tailadmin.entitlements.index',
        'principal.tailadmin.payment-providers.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    // ------------------------------------------------------- Access control

    public function test_every_governance_surface_boots_for_a_super_admin(): void
    {
        $this->actingAs($this->principal());

        foreach (self::INDEX_ROUTES as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_guests_are_redirected_away_from_every_surface(): void
    {
        foreach (self::INDEX_ROUTES as $route) {
            $this->get(route($route))->assertRedirect();
        }
    }

    public function test_finance_views_billing_but_cannot_manage_plans_or_entitlements(): void
    {
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);
        $this->actingAs($finance);

        $this->get(route('principal.tailadmin.subscriptions.index'))->assertOk();
        $this->get(route('principal.tailadmin.ai-credits.index'))->assertOk();
        $this->get(route('principal.tailadmin.payment-providers.index'))->assertOk();

        // Finance holds `platform.subscription.view` (read only) — never
        // `platform.plan.manage`.
        $this->get(route('principal.tailadmin.subscriptions.create'))->assertForbidden();
        // ...and holds no `platform.entitlement.manage`.
        $this->get(route('principal.tailadmin.entitlements.index'))->assertForbidden();
    }

    public function test_module_admin_manages_entitlements_but_not_plans_or_payments(): void
    {
        $admin = $this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN);
        $this->actingAs($admin);

        $this->get(route('principal.tailadmin.entitlements.index'))->assertOk();
        $this->get(route('principal.tailadmin.ai-credits.index'))->assertOk();

        $this->get(route('principal.tailadmin.subscriptions.index'))->assertForbidden();
        $this->get(route('principal.tailadmin.payment-providers.index'))->assertForbidden();
    }

    public function test_a_principal_without_billing_permission_cannot_grant_credits(): void
    {
        $wallet = $this->walletFor(Tenant::factory()->create(), 50);

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN));

        $this->post(route('principal.tailadmin.ai-credits.grant', $wallet), [
            'units' => 100,
            'reason' => 'Nope',
        ])->assertForbidden();
    }

    public function test_brand_manager_and_tenant_owner_are_denied_every_surface(): void
    {
        $actors = [
            $this->principal(RoleKey::BRAND_MANAGER),
            User::factory()->role(RoleKey::TENANT_OWNER)->create(),
        ];

        foreach ($actors as $actor) {
            $this->actingAs($actor);

            foreach (self::INDEX_ROUTES as $route) {
                $this->get(route($route))->assertForbidden();
            }
        }
    }

    // ----------------------------------------------------------- Subscriptions

    public function test_a_principal_can_create_update_archive_and_reactivate_a_plan(): void
    {
        $this->module('demo.plan');
        $this->actingAs($this->principal());

        $this->get(route('principal.tailadmin.subscriptions.create'))->assertOk();

        $payload = [
            'code' => 'starter',
            'name' => 'Starter',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'description' => 'Starter plan',
            'price_amount' => '120000',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 14,
            'seat_limit' => 5,
            'module_keys' => ['demo.plan'],
        ];

        $this->post(route('principal.tailadmin.subscriptions.store'), $payload)
            ->assertRedirect(route('principal.tailadmin.subscriptions.index'));

        $plan = SubscriptionPlan::query()->where('code', 'starter')->sole();
        $this->assertSame(['demo.plan'], $plan->moduleKeys());
        $this->assertSame(5, $plan->seat_limit);

        $this->get(route('principal.tailadmin.subscriptions.edit', $plan))
            ->assertOk()
            ->assertSee('Starter');

        $this->patch(route('principal.tailadmin.subscriptions.update', $plan), array_replace($payload, [
            'name' => 'Starter Plus',
            'seat_limit' => '',
            'module_keys' => [],
        ]))->assertRedirect(route('principal.tailadmin.subscriptions.index'));

        $plan->refresh();
        $this->assertSame('Starter Plus', $plan->name);
        $this->assertNull($plan->seat_limit);
        $this->assertSame([], $plan->moduleKeys());

        $this->patch(route('principal.tailadmin.subscriptions.archive', $plan))
            ->assertRedirect(route('principal.tailadmin.subscriptions.index'));
        $this->assertSame(SubscriptionPlan::STATUS_ARCHIVED, $plan->refresh()->status);

        $this->patch(route('principal.tailadmin.subscriptions.reactivate', $plan))
            ->assertRedirect(route('principal.tailadmin.subscriptions.index'));
        $this->assertSame(SubscriptionPlan::STATUS_ACTIVE, $plan->refresh()->status);
    }

    public function test_plan_codes_must_be_unique_and_only_licence_installed_modules(): void
    {
        $this->module('demo.plan');
        SubscriptionPlan::query()->create([
            'code' => 'halal',
            'name' => 'Halal',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => '0',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 0,
        ]);

        $this->actingAs($this->principal());

        $this->postJson(route('principal.tailadmin.subscriptions.store'), [
            'code' => 'halal',
            'name' => 'Duplicate',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => '0',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 0,
            'module_keys' => ['not-installed'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'module_keys.0']);
    }

    // -------------------------------------------------------------- AI credits

    public function test_a_principal_can_grant_ai_credits_through_the_surface(): void
    {
        $wallet = $this->walletFor(Tenant::factory()->create(), 100);

        $this->actingAs($this->principal());

        $this->post(route('principal.tailadmin.ai-credits.grant', $wallet), [
            'units' => 250,
            'reason' => 'Controlled top-up',
        ])->assertRedirect(route('principal.tailadmin.ai-credits.index'));

        $this->assertSame(350, $wallet->refresh()->available_credits);

        // The engine records ONE immutable, non-identifying ledger entry.
        $this->assertDatabaseHas('tenant_ai_wallet_ledger_entries', [
            'wallet_id' => $wallet->getKey(),
            'action' => 'grant',
            'credit_units' => 250,
        ]);
    }

    public function test_credit_grants_require_a_positive_amount(): void
    {
        $wallet = $this->walletFor(Tenant::factory()->create(), 10);

        $this->actingAs($this->principal());

        $this->postJson(route('principal.tailadmin.ai-credits.grant', $wallet), [
            'units' => 0,
            'reason' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['units', 'reason']);

        $this->assertSame(10, $wallet->refresh()->available_credits);
    }

    // ------------------------------------------------------------ Entitlements

    public function test_a_principal_can_grant_and_revoke_an_entitlement(): void
    {
        // `demo.showcase` is a genuinely declared, tenant-scoped module, so the
        // ModuleAdministration precondition accepts it — the write path does not
        // trust a catalogue row alone.
        $tenant = Tenant::factory()->create();
        $this->module('demo.showcase');
        $this->actingAs($this->principal());

        $index = route('principal.tailadmin.entitlements.index', ['tenant' => $tenant->getKey()]);

        $this->post(route('principal.tailadmin.entitlements.grant', $tenant), ['module_key' => 'demo.showcase'])
            ->assertRedirect($index);

        $this->assertTrue($this->entitlementFor($tenant, 'demo.showcase')->isGranted());

        $this->post(route('principal.tailadmin.entitlements.revoke', $tenant), ['module_key' => 'demo.showcase'])
            ->assertRedirect($index);

        $this->assertFalse($this->entitlementFor($tenant, 'demo.showcase')->isGranted());
    }

    public function test_only_installed_modules_can_be_granted(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs($this->principal());

        $this->postJson(route('principal.tailadmin.entitlements.grant', $tenant), ['module_key' => 'ghost.module'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_key');
    }

    public function test_member_roles_are_synced_within_the_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $membership = $this->giveMembership(User::factory()->create(), $tenant);

        $this->actingAs($this->principal());

        $this->patch(route('principal.tailadmin.entitlements.roles', [$tenant, $membership]), [
            'role_keys' => [RoleKey::TENANT_OWNER, RoleKey::RECEPTIONIST],
        ])->assertRedirect(route('principal.tailadmin.entitlements.index', ['tenant' => $tenant->getKey()]));

        $this->assertEqualsCanonicalizing(
            [RoleKey::TENANT_OWNER, RoleKey::RECEPTIONIST],
            $membership->refresh()->roles()->pluck('key')->all(),
        );
    }

    public function test_a_membership_from_another_tenant_can_never_be_mutated(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $membership = $this->giveMembership(User::factory()->create(), $otherTenant);

        $this->actingAs($this->principal());

        $this->patch(route('principal.tailadmin.entitlements.roles', [$tenant, $membership]), [
            'role_keys' => [RoleKey::TENANT_OWNER],
        ])->assertNotFound();

        $this->assertCount(0, $membership->refresh()->roles);
    }

    // -------------------------------------------------------- Payment providers

    public function test_a_principal_can_configure_and_verify_a_gateway(): void
    {
        $this->actingAs($this->principal());

        $this->get(route('principal.tailadmin.payment-providers.create'))->assertOk();

        $this->post(route('principal.tailadmin.payment-providers.store'), [
            'provider' => 'midtrans',
            'label' => 'Midtrans',
            'mode' => PaymentGateway::MODE_SANDBOX,
            'enabled' => '1',
            'currencies' => ['IDR'],
            'credential_server_key' => 'SB-Mid-server-xxx',
            'credential_client_key' => 'SB-Mid-client-yyy',
        ])->assertRedirect(route('principal.tailadmin.payment-providers.index'));

        $gateway = PaymentGateway::query()->where('provider', 'midtrans')->sole();
        $this->assertSame('SB-Mid-server-xxx', $gateway->credential('server_key'));
        $this->assertSame(['IDR'], $gateway->currencies);

        $this->patch(route('principal.tailadmin.payment-providers.verify', $gateway))
            ->assertRedirect(route('principal.tailadmin.payment-providers.index'));
        $this->assertTrue($gateway->refresh()->isVerified());

        $this->patch(route('principal.tailadmin.payment-providers.unverify', $gateway))
            ->assertRedirect(route('principal.tailadmin.payment-providers.index'));
        $this->assertFalse($gateway->refresh()->isVerified());
    }

    public function test_a_gateway_cannot_be_verified_without_its_credentials(): void
    {
        $gateway = PaymentGateway::factory()->provider('midtrans', 'Midtrans')->create();

        $this->actingAs($this->principal());

        $this->patch(route('principal.tailadmin.payment-providers.verify', $gateway))
            ->assertRedirect(route('principal.tailadmin.payment-providers.index'))
            ->assertSessionHasErrors('configured');

        $this->assertFalse($gateway->refresh()->isVerified());
    }

    public function test_editing_a_gateway_keeps_stored_secrets_when_left_blank(): void
    {
        $gateway = PaymentGateway::factory()
            ->provider('midtrans', 'Midtrans')
            ->credentials(['server_key' => 'stored', 'client_key' => 'stored-client'])
            ->create();

        $this->actingAs($this->principal());

        $this->patch(route('principal.tailadmin.payment-providers.update', $gateway), [
            'label' => 'Midtrans Renamed',
            'mode' => PaymentGateway::MODE_LIVE,
            'credential_server_key' => '',
        ])->assertRedirect(route('principal.tailadmin.payment-providers.index'));

        $gateway->refresh();
        $this->assertSame('stored', $gateway->credential('server_key'));
        $this->assertSame('Midtrans Renamed', $gateway->label);
        $this->assertSame(PaymentGateway::MODE_LIVE, $gateway->mode);
    }

    // --------------------------------------------------------------- Sidebar

    public function test_the_tailadmin_sidebar_exposes_the_governance_links(): void
    {
        $html = $this->actingAs($this->principal())->get('/principal/master-brands')->assertOk()->getContent();

        $this->assertStringContainsString(route('principal.tailadmin.subscriptions.index'), $html);
        $this->assertStringContainsString(route('principal.tailadmin.ai-credits.index'), $html);
        $this->assertStringContainsString(route('principal.tailadmin.entitlements.index'), $html);
        $this->assertStringContainsString(route('principal.tailadmin.payment-providers.index'), $html);
    }

    // ----------------------------------------------------------------- Helpers

    private function module(string $key): RegisteredModule
    {
        return RegisteredModule::factory()->forKey($key)->enabled()->create();
    }

    private function walletFor(Tenant $tenant, int $units): TenantAiWallet
    {
        $service = app(AiWalletService::class);
        $service->grant((string) $tenant->getKey(), $units, 'seed', 'seed-'.Str::ulid()->toBase32());

        $wallet = $service->walletFor((string) $tenant->getKey());
        $this->assertInstanceOf(TenantAiWallet::class, $wallet);

        return $wallet;
    }

    private function entitlementFor(Tenant $tenant, string $moduleKey): ModuleEntitlement
    {
        return ModuleEntitlement::query()
            ->where('module_key', $moduleKey)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();
    }
}
