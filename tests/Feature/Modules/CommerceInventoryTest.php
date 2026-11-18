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
use Modules\Commerce\Exceptions\InventoryException;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Models\StockMovement;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\ProductCatalog;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS3 — Commerce branch inventory (focused essentials).
 *
 * Proves the stock invariants the module promises: every movement is ledgered,
 * tenant context is required, and an issue can never drive a branch below zero.
 * Only synthetic products and branches are used.
 */
class CommerceInventoryTest extends TestCase
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

    private function product(string $name = 'Synthetic Product'): Product
    {
        return app(ProductCatalog::class)->create([
            'name' => $name,
            'base_currency' => 'IDR',
            'base_price' => 25000,
        ]);
    }

    public function test_receiving_stock_records_a_movement_and_raises_the_level(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);
        $product = $this->product();

        $service = app(InventoryService::class);
        $service->receive($product, (string) $branch->getKey(), 10, $owner, 'Opening stock');

        $this->assertSame(10, $service->level($product, (string) $branch->getKey()));

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->getKey(),
            'branch_id' => $branch->getKey(),
            'type' => StockMovement::TYPE_RECEIPT,
            'quantity' => 10,
            'balance_after' => 10,
        ], 'tenant');
    }

    public function test_an_issue_that_would_go_negative_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);
        $product = $this->product();

        $service = app(InventoryService::class);
        $service->receive($product, (string) $branch->getKey(), 5, $owner);

        try {
            $service->issue($product, (string) $branch->getKey(), 6, $owner);
            $this->fail('Expected an InventoryException for the over-issue.');
        } catch (InventoryException) {
            // Expected: negative stock is never recorded.
        }

        // The level is untouched and no issue movement was appended.
        $this->assertSame(5, $service->level($product, (string) $branch->getKey()));
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $product->getKey(),
            'type' => StockMovement::TYPE_ISSUE,
        ], 'tenant');
    }

    public function test_stock_is_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $branchA = $this->createBranch($tenantA);
        $productA = $this->product('Tenant A Product');
        app(InventoryService::class)->receive($productA, (string) $branchA->getKey(), 7, $ownerA);

        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->tenantDatabases()->configure((string) $tenantB->getKey());

        // The connection points at tenant B, so tenant A's ledger is invisible.
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_the_stock_resource_requires_an_active_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);

        // Module discovered but never activated: deny by default.
        $this->actingAs($owner)->get('/clinic/stock-levels')->assertForbidden();
    }

    public function test_the_stock_list_renders_for_an_entitled_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);
        $product = $this->product('Rendered Stock Product');
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 3, $owner);

        $this->actingAs($owner)->get('/clinic/stock-levels')->assertOk();
    }
}
