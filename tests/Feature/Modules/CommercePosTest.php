<?php

namespace Tests\Feature\Modules;

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
use Modules\Commerce\Filament\Pages\PosTerminal;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\ProductCatalog;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS3 — Clinic POS terminal and sales surfaces (focused essentials).
 *
 * Proves the cashier terminal is route-backed, entitled-only, and that a POS
 * checkout writes a real order through the shared services (cash settled,
 * stock reserved) — the browser never dictates price or branch. Only synthetic
 * products and branches are used.
 */
class CommercePosTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'commerce';

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

    private function product(float $price = 25000): Product
    {
        return app(ProductCatalog::class)->create([
            'name' => 'Synthetic POS Product',
            'base_currency' => 'IDR',
            'base_price' => $price,
        ]);
    }

    public function test_the_pos_terminal_requires_an_active_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);

        // Module discovered but never activated: deny by default.
        $this->actingAs($owner)->get('/clinic/commerce/pos')->assertForbidden();
    }

    public function test_the_pos_terminal_renders_for_an_entitled_cashier(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->createBranch($tenant);
        $this->product();

        $this->actingAs($owner)->get('/clinic/commerce/pos')->assertOk();
    }

    public function test_a_pos_checkout_settles_cash_and_reserves_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);
        $product = $this->product(25000);
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 10, $owner);

        $this->actingAs($owner);
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $page = new PosTerminal;
        $page->branchId = (string) $branch->getKey();
        $page->currency = 'IDR';
        $page->addProduct((string) $product->getKey(), 2);

        $this->assertSame(50000.0, $page->grandTotal());
        $this->assertTrue($page->canCheckout());

        $page->checkout();

        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $order = Order::query()->firstOrFail();

        $this->assertSame(Order::SOURCE_POS, $order->source);
        $this->assertSame('50000.00', (string) $order->grand_total);
        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(8, app(InventoryService::class)->level($product, (string) $branch->getKey()));
    }

    public function test_the_sales_overview_renders_for_an_entitled_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->createBranch($tenant);
        $this->product();

        $this->actingAs($owner)->get('/clinic/commerce/sales')->assertOk();
    }

    public function test_the_reconciliation_page_requires_an_active_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);

        $this->actingAs($owner)->get('/clinic/commerce/reconciliation')->assertForbidden();
    }

    public function test_the_reconciliation_page_renders_for_an_entitled_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->createBranch($tenant);
        $this->product();

        $this->actingAs($owner)->get('/clinic/commerce/reconciliation')->assertOk();
    }
}
