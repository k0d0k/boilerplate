<?php

namespace Tests\Feature\Modules;

use App\Models\PaymentGateway;
use App\Models\PaymentIntent;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Models\Merchant;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\MerchantRegistry;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\PaymentSettlement;
use Modules\Commerce\Services\ProductCatalog;
use Modules\Commerce\Services\ReconciliationService;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS8.D — operations & security (negative coverage).
 *
 * The adversarial half of the WS8 test plan. Every case here is an attempt to
 * break an isolation or money invariant and must FAIL closed:
 *  - tenant/brand/merchant data never crosses partners;
 *  - a client can never dictate the price of what it buys;
 *  - a provider failure leaves the order unpaid and releases the reservation;
 *  - gateway credentials are encrypted at rest and never surfaced;
 *  - a signed webhook for an unknown order, or a replay, changes nothing twice;
 *  - a commerce role grants no clinical/reviewer access and no role without the
 *    order permission can reach the reconciliation dashboard;
 *  - reconciliation never pools money across branches.
 *
 * Live payments are never enabled and no real charge is performed.
 */
class CommerceSecurityTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'commerce';

    private const SERVER_KEY = 'SB-Mid-server-synthetic';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    private function activate(Tenant $tenant): void
    {
        RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    /**
     * @return array{0: Tenant, 1: User, 2: string, 3: Product}
     */
    private function scaffold(int $stock = 10, float $price = 25000, bool $track = true): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($actor, $tenant, [RoleKey::TENANT_OWNER]);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);

        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic Security Product',
            'base_currency' => 'IDR',
            'base_price' => $price,
            'track_inventory' => $track,
        ]);

        if ($track) {
            app(InventoryService::class)->receive($product, (string) $branch->getKey(), $stock, $actor);
        }

        return [$tenant, $actor, (string) $branch->getKey(), $product];
    }

    private function midtransGateway(): PaymentGateway
    {
        return PaymentGateway::factory()
            ->provider('midtrans')
            ->enabled()
            ->credentials(['server_key' => self::SERVER_KEY, 'client_key' => 'SB-Mid-client-synthetic'])
            ->create();
    }

    /**
     * @param  array{0: Tenant, 1: User, 2: string, 3: Product}  $scaffold
     */
    private function placedOrder(array $scaffold, int $quantity = 2): Order
    {
        [, $actor, $branchId, $product] = $scaffold;

        return app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => $quantity],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_ONLINE,
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function signedPayload(Order $order, array $overrides = []): array
    {
        $payload = array_merge([
            'order_id' => (string) $order->order_number,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ], $overrides);

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY,
        );

        return $payload;
    }

    private function registerPendingIntent(string $tenantId, Order $order): PaymentIntent
    {
        return PaymentIntent::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => (string) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'provider' => 'midtrans',
            'provider_reference' => (string) $order->order_number,
            'currency' => 'IDR',
            'amount' => 50000,
            'status' => PaymentIntent::STATUS_PENDING,
            'idempotency_key' => 'midtrans:intent:'.$order->order_number,
        ]);
    }

    // ========================================= Tenant / merchant isolation

    public function test_commerce_data_never_crosses_tenants(): void
    {
        [$tenantA, $actorA, $branchId, $product] = $this->scaffold();

        $merchant = app(MerchantRegistry::class)->register($actorA, ['name' => 'Tenant A Merchant', 'currency' => 'IDR']);
        $order = $this->placedOrder([$tenantA, $actorA, $branchId, $product]);

        // A second, independent tenant is provisioned and bound.
        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->tenantDatabases()->configure((string) $tenantB->getKey());

        // Tenant B sees NONE of tenant A's commerce data — not even by id.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Merchant::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertNull(Order::query()->whereKey($order->getKey())->first());
        $this->assertNull(Merchant::query()->whereKey($merchant->getKey())->first());

        // Back on tenant A everything is intact.
        $this->tenantDatabases()->configure((string) $tenantA->getKey());
        $this->assertNotNull(Order::query()->whereKey($order->getKey())->first());
        $this->assertNotNull(Merchant::query()->whereKey($merchant->getKey())->first());
    }

    // ========================================= Price tampering

    public function test_a_client_supplied_price_is_ignored_at_checkout(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold(price: 25000);

        // The cart smuggles a price of 1 rupiah onto every line.
        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 2, 'unit_price' => 1, 'price' => 1, 'line_total' => 2],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
        ], $actor);

        $item = $order->items()->firstOrFail();

        // The catalog price wins; the tampered amount is discarded.
        $this->assertSame('25000.00', (string) $item->unit_price);
        $this->assertSame('50000.00', (string) $item->line_total);
        $this->assertSame('50000.00', (string) $order->grand_total);
    }

    // ========================================= Provider failure / delay

    public function test_a_provider_failure_leaves_the_order_unpaid_and_releases_the_reservation(): void
    {
        // The provider never answers successfully (a 500 / timeout).
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response('upstream error', 500)]);

        $this->midtransGateway();
        $scaffold = $this->scaffold();
        $order = $this->placedOrder($scaffold);

        try {
            app(PaymentManager::class)->charge($order, 'midtrans');
            $this->fail('A failed provider charge must not succeed.');
        } catch (PaymentException) {
            // Expected: the charge failed, nothing was settled.
        }

        // No intent is left behind (released for a clean retry) and no ledger row.
        $this->assertSame(0, PaymentIntent::query()->count());
        $this->assertSame(0, PaymentTransaction::query()->count());
        $this->assertFalse($order->refresh()->isPaid());
        $this->assertTrue($order->refresh()->isOpen());
        $this->assertSame(50000.0, $order->outstanding());
    }

    // ========================================= Credential safety

    public function test_provider_credentials_are_encrypted_and_never_surfaced(): void
    {
        $this->midtransGateway();

        // The stored column is ciphertext, not the plaintext secret.
        $raw = DB::connection((string) config('tenancy.central_connection'))
            ->table('payment_gateways')
            ->where('provider', 'midtrans')
            ->value('credentials');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString(self::SERVER_KEY, (string) $raw);

        // The provider option list is labels only — no credential material.
        $options = app(PaymentManager::class)->availableFor('IDR');
        $this->assertArrayHasKey('midtrans', $options);
        $this->assertStringNotContainsString(self::SERVER_KEY, json_encode($options));
    }

    // ========================================= Webhook security

    public function test_a_signed_webhook_for_an_unknown_order_is_rejected(): void
    {
        $this->midtransGateway();
        $scaffold = $this->scaffold();
        $order = $this->placedOrder($scaffold);

        // No intent was ever registered for this order.
        $this->tenantDatabases()->forget();

        $this->postJson('/api/v1/payments/midtrans/webhook', $this->signedPayload($order))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');
    }

    public function test_a_replayed_settlement_webhook_settles_only_once(): void
    {
        $this->midtransGateway();
        $scaffold = $this->scaffold();
        [$tenant] = $scaffold;
        $order = $this->placedOrder($scaffold);

        $this->registerPendingIntent((string) $tenant->getKey(), $order);
        $this->tenantDatabases()->forget();

        $payload = $this->signedPayload($order);

        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();

        // A verbatim replay must be a no-op: paid once, one succeeded row.
        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();

        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $this->assertTrue(Order::query()->find($order->getKey())->isPaid());
        $this->assertSame(
            1,
            PaymentTransaction::query()->where('status', PaymentTransaction::STATUS_SUCCEEDED)->count(),
        );
        $this->assertSame('50000.00', (string) Order::query()->find($order->getKey())->paid_total);
    }

    // ========================================= Privilege escalation

    public function test_a_role_without_the_order_permission_cannot_reach_reconciliation(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->createBranch($tenant);

        // LBA_OPERATOR holds no `order.manage`, so the dashboard is forbidden
        // even with the module entitled.
        $this->actingAs($operator)->get('/clinic/commerce/reconciliation')->assertForbidden();

        $this->assertNotContains(PermissionKey::ORDER_MANAGE, RbacCatalog::permissionsFor(RoleKey::LBA_OPERATOR));
    }

    public function test_the_commerce_role_never_grants_clinical_or_reviewer_access(): void
    {
        $permissions = RbacCatalog::permissionsFor(RoleKey::COMMERCE_MANAGER);

        // The commerce role holds the commerce/order permissions...
        $this->assertContains(PermissionKey::COMMERCE_MANAGE, $permissions);
        $this->assertContains(PermissionKey::ORDER_MANAGE, $permissions);

        // ...and NONE of the clinical, LBA or review permissions.
        foreach ([
            PermissionKey::EXAM_READ,
            PermissionKey::EXAM_CREATE,
            PermissionKey::EXAM_REVIEW,
            PermissionKey::EXAM_AI_START,
            PermissionKey::ANNOTATION_CREATE,
            PermissionKey::ANNOTATION_CORRECT,
            PermissionKey::PATIENT_PROFILE_READ,
            PermissionKey::PATIENT_PROFILE_MANAGE,
            PermissionKey::REPORT_APPROVE,
            PermissionKey::REPORT_PUBLISH,
            PermissionKey::WALLET_MANAGE,
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $permissions, "Commerce must not grant {$forbidden}.");
        }
    }

    // ========================================= Branch-scoped reconciliation

    public function test_reconciliation_is_branch_scoped(): void
    {
        $scaffold = $this->scaffold(stock: 100, track: false);
        [$tenant, $actor, $branchA] = $scaffold;

        $branchB = $this->createBranch($tenant, ['name' => 'Second Branch']);

        $order = $this->placedOrder($scaffold);
        app(PaymentSettlement::class)->record($order, ['provider' => 'cash', 'amount' => 50000]);

        // Branch A rings up the sale.
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $service = app(ReconciliationService::class);

        $scopedA = $service->summary([$branchA]);
        $this->assertSame(50000.0, $scopedA['IDR']['collected']);

        // Branch B must not see another branch's money.
        $scopedB = $service->summary([(string) $branchB->getKey()]);
        $this->assertSame(0.0, $scopedB['IDR']['collected']);
        $this->assertSame(0, $scopedB['IDR']['orders']);

        // No restriction (null) is the tenant-wide view.
        $tenantWide = $service->summary();
        $this->assertSame(50000.0, $tenantWide['IDR']['collected']);
    }
}
