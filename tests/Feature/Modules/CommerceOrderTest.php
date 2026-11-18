<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Exceptions\InventoryException;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Invoice;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\PaymentSettlement;
use Modules\Commerce\Services\ProductCatalog;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS3 — orders, checkout and payment settlement (focused essentials).
 *
 * Proves the money invariants: checkout reserves stock atomically (and refuses
 * to oversell), a settlement advances the order, and a duplicate/replayed
 * payment can neither overpay nor be recorded twice. Only synthetic data is
 * used.
 */
class CommerceOrderTest extends TestCase
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
            'name' => 'Synthetic Order Product',
            'base_currency' => 'IDR',
            'base_price' => $price,
        ]);

        app(InventoryService::class)->receive($product, (string) $branch->getKey(), $stock, $actor);

        return [$tenant, $actor, (string) $branch->getKey(), $product];
    }

    public function test_checkout_creates_an_order_invoice_and_reserves_stock(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold(stock: 10);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 3],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
        ], $actor);

        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame('75000.00', (string) $order->grand_total);
        $this->assertCount(1, $order->items);
        $this->assertSame('75000.00', (string) $order->invoices()->first()->amount);
        $this->assertSame(7, app(InventoryService::class)->level($product, $branchId));
    }

    public function test_checkout_refuses_to_oversell(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold(stock: 2);

        $this->expectException(InventoryException::class);

        app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 5],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
        ], $actor);
    }

    public function test_a_full_settlement_marks_the_order_paid(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold(stock: 10);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 2],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);

        app(PaymentSettlement::class)->record($order, [
            'provider' => 'cash',
            'amount' => 50000,
        ]);

        $order->refresh();

        $this->assertTrue($order->isPaid());
        $this->assertSame('50000.00', (string) $order->paid_total);
        $this->assertSame(Invoice::STATUS_PAID, $order->invoices()->first()->status);
    }

    public function test_a_duplicate_settlement_is_rejected(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold(stock: 10);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 2],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);

        $settlement = app(PaymentSettlement::class);
        $settlement->record($order, ['provider' => 'cash', 'amount' => 50000]);

        $this->expectException(OrderException::class);

        $settlement->record($order->refresh(), ['provider' => 'cash', 'amount' => 50000]);
    }

    public function test_a_replayed_webhook_is_idempotent(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold(stock: 10);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);

        $settlement = app(PaymentSettlement::class);

        $first = $settlement->record($order, [
            'provider' => 'midtrans',
            'provider_reference' => 'MID-ABC-1',
            'amount' => 25000,
        ]);

        $second = $settlement->record($order->refresh(), [
            'provider' => 'midtrans',
            'provider_reference' => 'MID-ABC-1',
            'amount' => 25000,
        ]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PaymentTransaction::query()->count());
    }

    public function test_a_payment_in_the_wrong_currency_is_refused(): void
    {
        [, $actor, $branchId, $product] = $this->scaffold(stock: 10);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);

        $this->expectException(OrderException::class);

        app(PaymentSettlement::class)->record($order, [
            'provider' => 'cash',
            'currency' => 'MYR',
            'amount' => 25000,
        ]);
    }
}
