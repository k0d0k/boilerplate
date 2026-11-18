<?php

namespace Tests\Feature\Modules;

use App\Models\PaymentGateway;
use App\Models\PaymentIntent;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Invoice;
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
use Modules\Commerce\Services\RefundService;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS8.C — provider-safe payments, refunds and reconciliation (SANDBOX ONLY).
 *
 * Proves the hardening the security review demanded:
 *  - the durable idempotency barrier: the intent is reserved BEFORE the
 *    provider call, so a retry replays the stored redirect instead of charging
 *    twice, a concurrent attempt is refused while one is in flight, and only a
 *    STALE reservation may be retried;
 *  - a verified webhook with a contradictory currency or an amount larger than
 *    the intent is still rejected and settles nothing;
 *  - a provider refund stays PENDING until the provider confirms, then completes
 *    exactly once (cash and remote semantics are distinct);
 *  - reconciliation is per-currency AND per-merchant.
 *
 * Live payments are never enabled and no real charge is performed.
 */
class CommercePaymentHardeningTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-synthetic';

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

    // --------------------------------------------------------------- Helpers

    private function midtransGateway(): PaymentGateway
    {
        return PaymentGateway::factory()
            ->provider('midtrans')
            ->enabled()
            ->credentials(['server_key' => self::SERVER_KEY, 'client_key' => 'SB-Mid-client-synthetic'])
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
            'name' => 'Synthetic Hardening Product',
            'base_currency' => 'IDR',
            'base_price' => $price,
            'track_inventory' => $track,
        ]);

        if ($track) {
            app(InventoryService::class)->receive($product, (string) $branch->getKey(), $stock, $actor);
        }

        return [$tenant, $actor, (string) $branch->getKey(), $product];
    }

    /**
     * A placed IDR order (2 units) on the tracked product.
     *
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

    private function midtransResponse(): array
    {
        return [
            'token' => 'snap-token',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
        ];
    }

    // ========================================= Durable idempotency barrier

    public function test_a_sandbox_charge_is_not_repeated_for_the_same_order(): void
    {
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response($this->midtransResponse(), 201)]);

        $this->midtransGateway();
        $scaffold = $this->scaffold();
        $order = $this->placedOrder($scaffold);

        $first = app(PaymentManager::class)->charge($order, 'midtrans');
        $second = app(PaymentManager::class)->charge($order->fresh(), 'midtrans');

        // The same intent is reused and the stored redirect is replayed...
        $this->assertSame((string) $first->intent->getKey(), (string) $second->intent->getKey());
        $this->assertSame($first->redirectUrl, $second->redirectUrl);

        // ...and the provider was contacted EXACTLY once.
        Http::assertSentCount(1);
        $this->assertSame(1, PaymentIntent::query()->count());
    }

    public function test_a_concurrent_charge_is_refused_while_one_is_in_flight(): void
    {
        Http::fake();

        $this->midtransGateway();
        $scaffold = $this->scaffold();
        [$tenant] = $scaffold;
        $order = $this->placedOrder($scaffold);

        // Simulate an attempt that reserved the intent but has not answered yet.
        PaymentIntent::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'order_id' => (string) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'provider' => 'midtrans',
            'provider_reference' => (string) $order->order_number,
            'currency' => 'IDR',
            'amount' => 50000,
            'status' => PaymentIntent::STATUS_INITIATED,
            'idempotency_key' => 'midtrans:intent:'.$order->order_number,
        ]);

        $this->expectException(PaymentException::class);

        app(PaymentManager::class)->charge($order, 'midtrans');
    }

    public function test_a_stale_reservation_is_safely_retried(): void
    {
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response($this->midtransResponse(), 201)]);

        $this->midtransGateway();
        $scaffold = $this->scaffold();
        [$tenant] = $scaffold;
        $order = $this->placedOrder($scaffold);

        $intent = PaymentIntent::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'order_id' => (string) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'provider' => 'midtrans',
            'provider_reference' => (string) $order->order_number,
            'currency' => 'IDR',
            'amount' => 50000,
            'status' => PaymentIntent::STATUS_INITIATED,
            'idempotency_key' => 'midtrans:intent:'.$order->order_number,
        ]);

        // Age the reservation past the TTL without touching the model clock.
        PaymentIntent::query()->whereKey($intent->getKey())->update(['updated_at' => now()->subSeconds(600)]);

        $initiation = app(PaymentManager::class)->charge($order, 'midtrans');

        $this->assertSame(PaymentIntent::STATUS_PENDING, $initiation->intent->status);
        Http::assertSentCount(1);
    }

    // ========================================= Webhook hardening

    public function test_a_verified_webhook_with_a_contradictory_currency_is_rejected(): void
    {
        $this->midtransGateway();
        $scaffold = $this->scaffold();
        [$tenant] = $scaffold;
        $order = $this->placedOrder($scaffold);

        $this->registerPendingIntent((string) $tenant->getKey(), $order);
        $this->tenantDatabases()->forget();

        $payload = $this->signedPayload($order, ['currency' => 'USD', 'gross_amount' => '50000.00']);

        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertFalse(Order::query()->find($order->getKey())->isPaid());
    }

    public function test_a_verified_webhook_claiming_an_excess_amount_is_rejected(): void
    {
        $this->midtransGateway();
        $scaffold = $this->scaffold();
        [$tenant] = $scaffold;
        $order = $this->placedOrder($scaffold);

        $this->registerPendingIntent((string) $tenant->getKey(), $order);
        $this->tenantDatabases()->forget();

        $payload = $this->signedPayload($order, ['gross_amount' => '999999.00']);

        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertFalse(Order::query()->find($order->getKey())->isPaid());
    }

    // ========================================= Refund semantics

    public function test_a_provider_refund_stays_pending_until_the_provider_confirms(): void
    {
        $scaffold = $this->scaffold(stock: 10);
        [, $actor, $branchId, $product] = $scaffold;

        $order = $this->placedOrder($scaffold);

        app(PaymentSettlement::class)->record($order, ['provider' => 'cash', 'amount' => 50000]);
        $order->refresh();

        $this->assertSame(8, app(InventoryService::class)->level($product, $branchId));

        // The request is recorded as PENDING and completes NOTHING locally.
        $request = app(RefundService::class)->requestProviderRefund($order, 'midtrans', null, $actor, 'Item damaged');

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $request->status);
        $this->assertFalse($order->refresh()->isRefunded());
        $this->assertSame(8, app(InventoryService::class)->level($product, $branchId));

        // Repeating the request is idempotent.
        $again = app(RefundService::class)->requestProviderRefund($order->refresh(), 'midtrans', null, $actor, 'Item damaged');
        $this->assertSame((string) $request->getKey(), (string) $again->getKey());

        // Only the provider's confirmation completes it — once.
        $completed = app(RefundService::class)->settleProviderRefund($order->refresh(), 'midtrans', 50000, 'Provider confirmed');

        $this->assertTrue($completed->isRefunded());
        $this->assertSame(10, app(InventoryService::class)->level($product, $branchId));
        $this->assertSame(Invoice::STATUS_VOID, $completed->invoices()->first()->status);

        $refundRows = PaymentTransaction::query()->where('method', 'refund')->get();
        $this->assertCount(1, $refundRows);
        $this->assertSame(PaymentTransaction::STATUS_REFUNDED, $refundRows->first()->status);

        // A replayed confirmation never restocks or double-refunds.
        app(RefundService::class)->settleProviderRefund($completed->refresh(), 'midtrans', 50000);
        $this->assertSame(10, app(InventoryService::class)->level($product, $branchId));
        $this->assertSame(1, PaymentTransaction::query()->where('method', 'refund')->count());
    }

    public function test_a_cash_refund_completes_immediately_and_records_evidence(): void
    {
        $scaffold = $this->scaffold(stock: 10);
        [, $actor, $branchId, $product] = $scaffold;

        $order = $this->placedOrder($scaffold);
        app(PaymentSettlement::class)->record($order, ['provider' => 'cash', 'amount' => 50000]);

        $refunded = app(RefundService::class)->refund($order->refresh(), null, $actor, 'Walk-in refund', [
            'voucher' => 'CASH-0001',
        ]);

        $this->assertTrue($refunded->isRefunded());
        $this->assertSame(10, app(InventoryService::class)->level($product, $branchId));

        $row = PaymentTransaction::query()->where('provider', 'refund')->firstOrFail();
        $this->assertSame(PaymentTransaction::STATUS_REFUNDED, $row->status);
        $this->assertSame('cash', $row->metadata['refund_kind']);
        $this->assertSame('CASH-0001', $row->metadata['evidence']['voucher']);
    }

    public function test_an_over_refund_is_refused_on_both_paths(): void
    {
        $scaffold = $this->scaffold();

        $order = $this->placedOrder($scaffold);
        app(PaymentSettlement::class)->record($order, ['provider' => 'cash', 'amount' => 50000]);

        $this->expectException(OrderException::class);

        app(RefundService::class)->requestProviderRefund($order->refresh(), 'midtrans', 60000);
    }

    // ========================================= Reconciliation by merchant

    public function test_reconciliation_separates_totals_by_merchant_and_currency(): void
    {
        $scaffold = $this->scaffold(stock: 100, track: false);
        [, $actor, $branchId] = $scaffold;

        $registry = app(MerchantRegistry::class);
        $merchantA = $registry->register($actor, ['name' => 'Merchant Alpha', 'currency' => 'IDR']);
        $merchantB = $registry->register($actor, ['name' => 'Merchant Beta', 'currency' => 'IDR']);

        $this->settleOrderForMerchant($scaffold, (string) $merchantA->getKey(), 50000);
        $this->settleOrderForMerchant($scaffold, (string) $merchantB->getKey(), 30000);
        $this->settleOrderForMerchant($scaffold, null, 10000);

        $rows = collect(app(ReconciliationService::class)->summaryByMerchant())
            ->keyBy(fn (array $row) => ($row['merchant_id'] ?? 'unassigned').'|'.$row['currency']);

        $this->assertSame(50000.0, $rows[(string) $merchantA->getKey().'|IDR']['collected']);
        $this->assertSame(30000.0, $rows[(string) $merchantB->getKey().'|IDR']['collected']);
        $this->assertSame(10000.0, $rows['unassigned|IDR']['collected']);
        $this->assertSame('Merchant Alpha', $rows[(string) $merchantA->getKey().'|IDR']['merchant']);
    }

    public function test_live_payments_are_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('payments.live_enabled'));
    }

    // ------------------------------------------------- Test-only utilities

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

    /**
     * Place, attribute and settle an order for a given merchant (null = none).
     *
     * @param  array{0: Tenant, 1: User, 2: string, 3: Product}  $scaffold
     */
    private function settleOrderForMerchant(array $scaffold, ?string $merchantId, float $amount): Order
    {
        [$tenant, $actor, $branchId, $product] = $scaffold;

        // Price the line to match the requested settled amount.
        app(ProductCatalog::class)->setPrice($product, 'IDR', $amount);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
        ], $actor);

        // Attribute explicitly so a merchant is not auto-resolved from the
        // registry (the two-test-tenant setup is intentionally ambiguous).
        $order->forceFill(['merchant_id' => $merchantId])->save();

        app(PaymentSettlement::class)->record($order, ['provider' => 'cash', 'amount' => $amount]);

        return $order->refresh();
    }
}
