<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Models\InventoryStock;
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
 * WS3 via the Clinic TailAdmin (Blade) surface — the catalog, branch stock and
 * the order book.
 *
 * These are the AUTHORIZATION, branch-scope and money/stock essentials of the
 * Blade counterparts of ProductResource / StockLevelResource / OrderResource:
 * every route and action re-derives, on the server, the module entitlement, the
 * acting permission (commerce.manage / order.manage), the verified tenant
 * context and the branch scope. Catalog, stock and order mutations all run
 * through the SHARED domain services, so the domain's transaction and audit
 * rules still hold. Every identity, branch, product and order here is synthetic.
 */
class ClinicCommerceTailAdminTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

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

    /**
     * @param  list<string>  $modules
     */
    private function activateModules(Tenant $tenant, array $modules): void
    {
        foreach ($modules as $module) {
            if (! RegisteredModule::query()->where('module_key', $module)->exists()) {
                RegisteredModule::factory()->forKey($module)->enabled()->create();
            }

            ModuleInstallation::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->enabled()->create();
            ModuleEntitlement::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->granted()->create();
        }
    }

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    private function bind(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function product(string $name = 'Synthetic TailAdmin Product', float $price = 25000): Product
    {
        return app(ProductCatalog::class)->create([
            'name' => $name,
            'base_currency' => 'IDR',
            'base_price' => $price,
        ]);
    }

    // ============================== 1. Entitlement and permission gating

    public function test_catalog_stock_and_orders_require_the_module_and_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);

        // Module discovered but never activated: deny by default.
        $this->actingAs($owner)->get(route('clinic.tailadmin.products'))->assertForbidden();
        $this->actingAs($owner)->get(route('clinic.tailadmin.inventory'))->assertForbidden();
        $this->actingAs($owner)->get(route('clinic.tailadmin.orders'))->assertForbidden();

        $this->activateModules($tenant, ['commerce']);
        $this->createBranch($tenant);

        // The entitled owner reaches all three surfaces.
        $this->actingAs($owner)->get(route('clinic.tailadmin.products'))->assertOk();
        $this->actingAs($owner)->get(route('clinic.tailadmin.inventory'))->assertOk();
        $this->actingAs($owner)->get(route('clinic.tailadmin.orders'))->assertOk();

        // An identity without commerce.manage / order.manage is denied outright.
        $this->actingAs($operator)->get(route('clinic.tailadmin.products'))->assertForbidden();
        $this->actingAs($operator)->get(route('clinic.tailadmin.inventory'))->assertForbidden();
        $this->actingAs($operator)->get(route('clinic.tailadmin.orders'))->assertForbidden();
    }

    // ============================== 2. Catalog lifecycle via the shared service

    public function test_product_catalog_create_update_and_archive_through_the_catalog_service(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant, ['commerce']);
        $this->createBranch($tenant);

        // Create — the FormRequest authorises via ProductResource::canViewAny().
        $this->actingAs($owner)->post(route('clinic.tailadmin.products.store'), [
            'name' => 'Synthetic TailAdmin Product',
            'kind' => 'product',
            'status' => 'active',
            'base_currency' => 'IDR',
            'base_price' => 25000,
            'track_inventory' => '1',
        ])->assertRedirect(route('clinic.tailadmin.products'));

        $this->bind($tenant);
        $product = Product::query()->firstOrFail();
        $this->assertSame('Synthetic TailAdmin Product', $product->name);
        $this->assertTrue($product->track_inventory);
        // The catalog service generates a tenant-unique slug/SKU when omitted.
        $this->assertNotSame('', (string) $product->slug);
        $this->assertNotSame('', (string) $product->sku);

        // Update.
        $this->actingAs($owner)->put(route('clinic.tailadmin.products.update', $product), [
            'name' => 'Renamed TailAdmin Product',
            'kind' => 'product',
            'status' => 'active',
            'base_currency' => 'IDR',
            'base_price' => 30000,
        ])->assertRedirect(route('clinic.tailadmin.products'));

        $this->bind($tenant);
        $this->assertSame('Renamed TailAdmin Product', $product->fresh()->name);

        // Archive is non-destructive: the row is retained, only status changes.
        $this->actingAs($owner)->post(route('clinic.tailadmin.products.archive', $product))
            ->assertRedirect(route('clinic.tailadmin.products'));

        $this->bind($tenant);
        $this->assertTrue($product->fresh()->isArchived());
        $this->assertSame(1, Product::query()->count());
    }

    // ============================== 3. Branch stock and scope

    public function test_branch_stock_receive_and_adjust_are_scoped_to_eligible_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $manager = $this->member($tenant, RoleKey::COMMERCE_MANAGER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant, ['commerce']);

        $branchA = $this->createBranch($tenant, ['name' => 'Branch A']);
        $branchB = $this->createBranch($tenant, ['name' => 'Branch B']);
        // The owner manages branches (sees all); the commerce manager only sees
        // the branch they are explicitly assigned to.
        $this->assignStaff($owner, $manager, $branchA->id);

        $this->bind($tenant);
        $product = $this->product();
        app(InventoryService::class)->receive($product, (string) $branchA->getKey(), 10, $owner);
        app(InventoryService::class)->receive($product, (string) $branchB->getKey(), 5, $owner);

        $this->bind($tenant);
        $stockA = InventoryStock::query()
            ->where('product_id', $product->getKey())
            ->where('branch_id', $branchA->getKey())
            ->firstOrFail();
        $stockB = InventoryStock::query()
            ->where('product_id', $product->getKey())
            ->where('branch_id', $branchB->getKey())
            ->firstOrFail();

        // The assigned branch accepts a receipt.
        $this->actingAs($manager)
            ->post(route('clinic.tailadmin.inventory.receive', $stockA), ['quantity' => 4])
            ->assertRedirect(route('clinic.tailadmin.inventory'));

        $this->bind($tenant);
        $this->assertSame(14, app(InventoryService::class)->level($product, (string) $branchA->getKey()));

        // A branch the manager may NOT operate in is refused by the server.
        $this->actingAs($manager)
            ->post(route('clinic.tailadmin.inventory.receive', $stockB), ['quantity' => 4])
            ->assertForbidden();

        $this->bind($tenant);
        $this->assertSame(5, app(InventoryService::class)->level($product, (string) $branchB->getKey()));

        // Stock-take correction (set the exact count) on the eligible branch.
        $this->actingAs($manager)
            ->post(route('clinic.tailadmin.inventory.adjust', $stockA), ['quantity' => 7])
            ->assertRedirect(route('clinic.tailadmin.inventory'));

        $this->bind($tenant);
        $this->assertSame(7, app(InventoryService::class)->level($product, (string) $branchA->getKey()));
    }

    // ============================== 4. Order book: show, pay, fulfil, cancel, refund

    public function test_order_book_show_pay_fulfil_cancel_and_refund(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant, ['commerce']);

        $branch = $this->createBranch($tenant, ['name' => 'Order Branch']);

        $this->bind($tenant);
        $product = $this->product();
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 30, $owner);

        $paid = $this->placeOrder($owner, $product, (string) $branch->getKey(), 2);
        $pending = $this->placeOrder($owner, $product, (string) $branch->getKey(), 3);

        // Placement reserved stock through the shared service (30 - 2 - 3).
        $this->bind($tenant);
        $this->assertSame(25, app(InventoryService::class)->level($product, (string) $branch->getKey()));

        // The order dossier renders for an entitled operator.
        $this->actingAs($owner)->get(route('clinic.tailadmin.orders.show', $paid))
            ->assertOk()
            ->assertSee((string) $paid->order_number);

        // Settle the first order, then fulfil it.
        $this->bind($tenant);
        app(PaymentSettlement::class)->settle(
            $paid,
            'manual',
            'synthetic-ref-1',
            PaymentTransaction::STATUS_SUCCEEDED,
            (float) $paid->grand_total,
            'IDR',
        );
        $this->assertTrue($paid->fresh()->isPaid());

        $this->actingAs($owner)->post(route('clinic.tailadmin.orders.fulfil', $paid))
            ->assertRedirect(route('clinic.tailadmin.orders.show', $paid));

        $this->bind($tenant);
        $this->assertSame(Order::FULFILLMENT_FULFILLED, $paid->fresh()->fulfillment_status);

        // Cancelling an unpaid order returns its reserved stock.
        $this->actingAs($owner)->post(route('clinic.tailadmin.orders.cancel', $pending), [
            'reason' => 'Synthetic cancellation',
        ])->assertRedirect(route('clinic.tailadmin.orders.show', $pending));

        $this->bind($tenant);
        $this->assertTrue($pending->fresh()->isCancelled());
        $this->assertSame(28, app(InventoryService::class)->level($product, (string) $branch->getKey()));

        // Refunding the settled order returns its reserved stock and marks it
        // refunded — through the same RefundService the TillAdmin surface uses.
        $this->actingAs($owner)->post(route('clinic.tailadmin.orders.refund', $paid), [
            'reason' => 'Synthetic refund',
        ])->assertRedirect(route('clinic.tailadmin.orders.show', $paid));

        $this->bind($tenant);
        $this->assertTrue($paid->fresh()->isRefunded());
        $this->assertSame(30, app(InventoryService::class)->level($product, (string) $branch->getKey()));
    }

    // ============================== 5. Order branch scope

    public function test_order_book_is_scoped_to_eligible_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $manager = $this->member($tenant, RoleKey::COMMERCE_MANAGER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant, ['commerce']);

        $branchA = $this->createBranch($tenant, ['name' => 'Scope A']);
        $branchB = $this->createBranch($tenant, ['name' => 'Scope B']);
        $this->assignStaff($owner, $manager, $branchA->id);

        $this->bind($tenant);
        $product = $this->product();
        app(InventoryService::class)->receive($product, (string) $branchA->getKey(), 10, $owner);
        app(InventoryService::class)->receive($product, (string) $branchB->getKey(), 10, $owner);

        $orderA = $this->placeOrder($owner, $product, (string) $branchA->getKey(), 1);
        $orderB = $this->placeOrder($owner, $product, (string) $branchB->getKey(), 1);

        // The manager sees the order book of the assigned branch only.
        $this->actingAs($manager)->get(route('clinic.tailadmin.orders'))
            ->assertOk()
            ->assertSee((string) $orderA->order_number)
            ->assertDontSee((string) $orderB->order_number);

        // And cannot open the dossier of a foreign-branch order.
        $this->actingAs($manager)->get(route('clinic.tailadmin.orders.show', $orderB))
            ->assertForbidden();
    }

    private function placeOrder(User $actor, Product $product, string $branchId, int $quantity): Order
    {
        $this->bind(Tenant::query()->firstOrFail());

        return app(OrderService::class)->place(
            [['product_id' => (string) $product->getKey(), 'quantity' => $quantity]],
            ['branch_id' => $branchId, 'currency' => 'IDR', 'source' => Order::SOURCE_POS],
            $actor,
        );
    }
}
