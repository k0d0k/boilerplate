<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Exceptions\InventoryException;
use Modules\Commerce\Exceptions\MerchantException;
use Modules\Commerce\Models\Merchant;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Models\StockMovement;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\MerchantRegistry;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Modules\Commerce\Support\Currency;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS8.A — merchant of record, order linkage, branch stock transfers and
 * currency-safe amounts.
 *
 * Proves that money can only be attributed to an explicit, auditable merchant
 * mapping, that merchants never leak across partners, that branch stock moves
 * are race-safe and never negative, and that minor-unit amounts are exact.
 */
class CommerceMerchantTest extends TestCase
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

    /**
     * @return array{0: Tenant, 1: User, 2: TenantBranch}
     */
    private function bootTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($actor, $tenant, [RoleKey::TENANT_OWNER]);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant, ['timezone' => 'UTC']);

        return [$tenant, $actor, $branch];
    }

    private function registerMerchant(User $actor, string $currency = 'IDR', mixed $brandId = null): Merchant
    {
        return app(MerchantRegistry::class)->register($actor, [
            'name' => 'Synthetic Merchant Of Record',
            'legal_name' => 'PT Sintetis Sehat',
            'currency' => $currency,
            'brand_id' => $brandId,
            'country' => 'ID',
            'invoice_issuer' => 'PT Sintetis Sehat',
        ]);
    }

    private function product(User $actor, string $currency = 'IDR', float $price = 25000): Product
    {
        return app(ProductCatalog::class)->create([
            'name' => 'Synthetic Merchant Product',
            'base_currency' => $currency,
            'base_price' => $price,
            // Merchant linkage is the subject here; stock is exercised
            // separately through the transfer tests below.
            'track_inventory' => false,
        ]);
    }

    public function test_a_placed_order_records_its_merchant_of_record(): void
    {
        [, $actor, $branch] = $this->bootTenant();

        $merchant = $this->registerMerchant($actor);
        $product = $this->product($actor);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => (string) $branch->getKey(),
            'currency' => 'IDR',
        ], $actor);

        $this->assertSame((string) $merchant->getKey(), (string) $order->merchant_id);
        $this->assertDatabaseHas('orders', [
            'id' => $order->getKey(),
            'merchant_id' => $merchant->getKey(),
        ], 'tenant');
    }

    public function test_an_order_without_a_configured_merchant_keeps_a_null_merchant(): void
    {
        [, $actor, $branch] = $this->bootTenant();

        $product = $this->product($actor);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => (string) $branch->getKey(),
            'currency' => 'IDR',
        ], $actor);

        $this->assertNull($order->merchant_id);
    }

    public function test_an_ambiguous_merchant_mapping_fails_closed(): void
    {
        [, $actor] = $this->bootTenant();

        // Two IDR merchants, neither is the tenant default (both carry a brand).
        $this->registerMerchant($actor, 'IDR', 'brand-a');
        $this->registerMerchant($actor, 'IDR', 'brand-b');

        $this->expectException(MerchantException::class);

        app(MerchantRegistry::class)->requireForCurrency('IDR');
    }

    public function test_a_tenant_default_merchant_resolves_when_brands_also_exist(): void
    {
        [, $actor] = $this->bootTenant();

        $default = $this->registerMerchant($actor, 'IDR', null);
        $this->registerMerchant($actor, 'IDR', 'brand-a');

        $resolved = app(MerchantRegistry::class)->forCurrency('IDR');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $default->getKey(), (string) $resolved->getKey());
    }

    public function test_a_merchant_cannot_settle_a_currency_it_does_not_own(): void
    {
        [, $actor] = $this->bootTenant();

        $merchant = $this->registerMerchant($actor, 'IDR');

        $this->expectException(MerchantException::class);

        app(MerchantRegistry::class)->assertCanSettle($merchant, 'MYR');
    }

    public function test_a_deactivated_merchant_cannot_settle(): void
    {
        [, $actor] = $this->bootTenant();

        $merchant = $this->registerMerchant($actor, 'IDR');
        $merchant->forceFill(['status' => Merchant::STATUS_INACTIVE])->save();

        $this->expectException(MerchantException::class);

        app(MerchantRegistry::class)->assertCanSettle($merchant->refresh(), 'IDR');
    }

    public function test_merchants_are_isolated_between_tenants(): void
    {
        [$tenantA, $actorA] = $this->bootTenant();
        $merchantA = $this->registerMerchant($actorA);

        // Switch to a second, independent tenant database.
        $tenantB = Tenant::factory()->create();
        $actorB = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($actorB, $tenantB, [RoleKey::TENANT_OWNER]);
        $this->provisionTenantDatabase($tenantB);
        $this->tenantDatabases()->configure((string) $tenantB->getKey());

        $this->assertSame(0, Merchant::query()->count());
        $this->assertNull(Merchant::query()->whereKey($merchantA->getKey())->first());

        // And back on tenant A the merchant is intact.
        $this->tenantDatabases()->configure((string) $tenantA->getKey());
        $this->assertNotNull(Merchant::query()->whereKey($merchantA->getKey())->first());
    }

    public function test_stock_moves_between_branches_with_a_balanced_ledger(): void
    {
        [$tenant, $actor, $branchA] = $this->bootTenant();
        $branchB = $this->createBranch($tenant, ['timezone' => 'UTC']);

        $product = $this->product($actor);

        $inventory = app(InventoryService::class);
        $inventory->receive($product, (string) $branchA->getKey(), 10, $actor);
        $inventory->transfer($product, (string) $branchA->getKey(), (string) $branchB->getKey(), 4, $actor, 'Rebalance');

        $this->assertSame(6, $inventory->level($product, (string) $branchA->getKey()));
        $this->assertSame(4, $inventory->level($product, (string) $branchB->getKey()));

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->getKey(),
            'branch_id' => $branchA->getKey(),
            'type' => StockMovement::TYPE_TRANSFER_OUT,
            'quantity' => -4,
            'balance_after' => 6,
        ], 'tenant');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->getKey(),
            'branch_id' => $branchB->getKey(),
            'type' => StockMovement::TYPE_TRANSFER_IN,
            'quantity' => 4,
            'balance_after' => 4,
        ], 'tenant');
    }

    public function test_a_transfer_cannot_drive_a_branch_below_zero(): void
    {
        [$tenant, $actor, $branchA] = $this->bootTenant();
        $branchB = $this->createBranch($tenant, ['timezone' => 'UTC']);

        $product = $this->product($actor);
        app(InventoryService::class)->receive($product, (string) $branchA->getKey(), 3, $actor);

        $this->expectException(InventoryException::class);

        app(InventoryService::class)->transfer($product, (string) $branchA->getKey(), (string) $branchB->getKey(), 5, $actor);
    }

    public function test_a_transfer_to_the_same_branch_is_refused(): void
    {
        [, $actor, $branch] = $this->bootTenant();

        $product = $this->product($actor);

        $this->expectException(InventoryException::class);

        app(InventoryService::class)->transfer($product, (string) $branch->getKey(), (string) $branch->getKey(), 1, $actor);
    }

    public function test_currency_minor_units_are_exact(): void
    {
        // IDR is a zero-decimal currency: minor units are whole rupiah.
        $this->assertSame(25000, Currency::toMinor('25000', Currency::IDR));
        $this->assertSame(25000, Currency::toMinor(25000.4, Currency::IDR));
        $this->assertSame(25000.0, Currency::fromMinor(25000, Currency::IDR));

        // MYR keeps two decimals: minor units are sen.
        $this->assertSame(12345, Currency::toMinor('123.45', Currency::MYR));
        $this->assertSame(123.45, Currency::fromMinor(12345, Currency::MYR));

        // The classic float trap is exact in minor units.
        $this->assertSame(
            Currency::toMinor(0.1, Currency::MYR) + Currency::toMinor(0.2, Currency::MYR),
            Currency::toMinor(0.3, Currency::MYR),
        );
    }
}
