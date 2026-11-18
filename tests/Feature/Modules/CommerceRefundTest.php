<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Invoice;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Services\InventoryService;
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
 * WS3 — refunds, cancellations and reconciliation (focused essentials).
 *
 * Proves the money invariants around reversals: a settled order can be refunded
 * exactly once (idempotent), stock is returned exactly once, invoices are voided,
 * a refund can never exceed what was settled, an unsettled order cannot be
 * refunded, and reconciliation reports per-currency drift between what orders
 * record and what the ledger holds. Only synthetic data is used.
 */
class CommerceRefundTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

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

    private function actor(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    /**
     * @return array{0: Tenant, 1: User, 2: string, 3: Product}
     */
    private function scaffold(int $stock = 10, float $price = 25000): array
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->actor($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic Refund Product',
            'base_currency' => 'IDR',
            'base_price' => $price,
        ]);

        app(InventoryService::class)->receive($product, (string) $branch->getKey(), $stock, $actor);

        return [$tenant, $actor, (string) $branch->getKey(), $product];
    }

    /**
     * Place and fully settle a two-unit, IDR 50,000 order.
     *
     * @param  array{0: Tenant, 1: User, 2: string, 3: Product}  $scaffold
     */
    private function settledOrder(array $scaffold): Order
    {
        [, $actor, $branchId, $product] = $scaffold;

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 2],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);

        app(PaymentSettlement::class)->record($order, [
            'provider' => 'cash',
            'amount' => 50000,
        ]);

        return $order->refresh();
    }

    public function test_a_full_refund_returns_stock_voids_invoices_and_marks_the_order_refunded(): void
    {
        $scaffold = $this->scaffold(stock: 10);
        [, $actor, $branchId, $product] = $scaffold;

        $order = $this->settledOrder($scaffold);

        $this->assertSame(Invoice::STATUS_PAID, $order->invoices()->first()->status);
        $this->assertSame(8, app(InventoryService::class)->level($product, $branchId));

        $refunded = app(RefundService::class)->refund($order, null, $actor, 'Customer changed mind');

        $this->assertSame(Order::STATUS_REFUNDED, $refunded->status);
        $this->assertSame(Order::FULFILLMENT_RETURNED, $refunded->fulfillment_status);
        $this->assertTrue($refunded->isRefunded());

        // Stock is returned to the branch exactly once — back to the original 10.
        $this->assertSame(10, app(InventoryService::class)->level($product, $branchId));

        // Invoices are voided, and a distinct refunded ledger row is appended.
        $this->assertSame(Invoice::STATUS_VOID, $refunded->invoices()->first()->status);

        $refundLedger = PaymentTransaction::query()->where('provider', 'refund')->get();
        $this->assertCount(1, $refundLedger);
        $this->assertSame(PaymentTransaction::STATUS_REFUNDED, $refundLedger->first()->status);
        $this->assertSame('50000.00', (string) $refundLedger->first()->amount);
    }

    public function test_a_refund_beyond_the_settled_amount_is_refused(): void
    {
        $scaffold = $this->scaffold();
        [, $actor] = $scaffold;

        $order = $this->settledOrder($scaffold);

        $this->expectException(OrderException::class);

        app(RefundService::class)->refund($order, 60000, $actor);
    }

    public function test_a_refund_is_idempotent_so_stock_and_ledger_are_never_doubled(): void
    {
        $scaffold = $this->scaffold(stock: 10);
        [, $actor, $branchId, $product] = $scaffold;

        $order = $this->settledOrder($scaffold);

        $service = app(RefundService::class);
        $service->refund($order, null, $actor);

        // Replay the same refund against the stale in-memory order instance.
        $service->refund($order, null, $actor);

        $this->assertSame(1, PaymentTransaction::query()->where('provider', 'refund')->count());
        $this->assertSame(10, app(InventoryService::class)->level($product, $branchId));
    }

    public function test_an_order_without_a_settled_payment_cannot_be_refunded(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold();

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);

        $this->expectException(OrderException::class);

        app(RefundService::class)->refund($order, null, $actor);
    }

    public function test_cancelling_an_unsettled_order_returns_stock_and_voids_invoices(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold(stock: 10);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 3],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);

        $this->assertSame(7, app(InventoryService::class)->level($product, $branchId));

        $cancelled = app(OrderService::class)->cancel($order, $actor, 'Walked away');

        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(Order::FULFILLMENT_CANCELLED, $cancelled->fulfillment_status);
        $this->assertSame(10, app(InventoryService::class)->level($product, $branchId));
        $this->assertSame(Invoice::STATUS_VOID, $cancelled->invoices()->first()->status);
    }

    public function test_reconciliation_summarises_collected_and_refunded_per_currency(): void
    {
        $scaffold = $this->scaffold();
        [, $actor] = $scaffold;

        $order = $this->settledOrder($scaffold);

        $summary = app(ReconciliationService::class)->summary();

        $this->assertArrayHasKey('IDR', $summary);
        $this->assertArrayHasKey('MYR', $summary);
        $this->assertSame(50000.0, $summary['IDR']['collected']);
        $this->assertSame(0.0, $summary['IDR']['refunded']);
        $this->assertSame(1, $summary['IDR']['orders']);
        $this->assertSame(1, $summary['IDR']['settled_orders']);
        // The MYR line must never absorb an IDR total.
        $this->assertSame(0.0, $summary['MYR']['collected']);

        app(RefundService::class)->refund($order->refresh(), null, $actor);

        $afterRefund = app(ReconciliationService::class)->summary();

        $this->assertSame(50000.0, $afterRefund['IDR']['refunded']);
    }

    public function test_reconciliation_flags_an_order_whose_recorded_total_drifts_from_the_ledger(): void
    {
        $scaffold = $this->scaffold();

        $order = $this->settledOrder($scaffold);

        $this->assertSame([], app(ReconciliationService::class)->discrepancies());

        // Simulate drift: the order claims more was collected than the ledger holds.
        $order->forceFill(['paid_total' => 40000])->save();

        $rows = app(ReconciliationService::class)->discrepancies();

        $this->assertCount(1, $rows);
        $this->assertSame((string) $order->order_number, $rows[0]['order_number']);
        $this->assertSame('IDR', $rows[0]['currency']);
        $this->assertSame('10000', $rows[0]['difference']);
    }
}
