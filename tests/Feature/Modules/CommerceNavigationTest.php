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
use Illuminate\Support\Facades\Route;
use Modules\Commerce\Filament\Pages\PosTerminal;
use Modules\Commerce\Filament\Pages\Reconciliation;
use Modules\Commerce\Filament\Pages\SalesOverview;
use Modules\Commerce\Filament\Resources\InvoiceResource;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Filament\Resources\PaymentTransactionResource;
use Modules\Commerce\Filament\Resources\ProductCategoryResource;
use Modules\Commerce\Filament\Resources\ProductResource;
use Modules\Commerce\Filament\Resources\StockLevelResource;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS3 — Commerce navigation & dashboards (focused essentials).
 *
 * Proves the Commerce module is exposed through REAL, route-backed Filament
 * navigation in the Clinic panel and nothing more: every catalog, inventory,
 * order, invoice, transaction, POS, sales and reconciliation surface is a live
 * route, all entries share one navigation group and carry unique sorts (no
 * duplicate menu items), and the group renders on the Clinic dashboard for an
 * entitled identity. Only synthetic data is used.
 */
class CommerceNavigationTest extends TestCase
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
        if (! RegisteredModule::query()->where('module_key', self::MODULE)->exists()) {
            RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();
        }

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

    public function test_every_commerce_clinic_surface_is_route_backed(): void
    {
        foreach ([
            'filament.clinic.resources.product-categories.index',
            'filament.clinic.resources.products.index',
            'filament.clinic.resources.stock-levels.index',
            'filament.clinic.resources.orders.index',
            'filament.clinic.resources.invoices.index',
            'filament.clinic.resources.payment-transactions.index',
            'filament.clinic.pages.commerce.sales',
            'filament.clinic.pages.commerce.pos',
            'filament.clinic.pages.commerce.reconciliation',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing Commerce route [{$name}].");
        }
    }

    public function test_commerce_navigation_entries_share_one_group_and_have_unique_sorts(): void
    {
        $group = __('lba.nav.clinic.operations');

        /** @var list<class-string> $surfaces */
        $surfaces = [
            ProductCategoryResource::class,
            ProductResource::class,
            StockLevelResource::class,
            OrderResource::class,
            InvoiceResource::class,
            PaymentTransactionResource::class,
            SalesOverview::class,
            PosTerminal::class,
            Reconciliation::class,
        ];

        $sorts = [];

        foreach ($surfaces as $surface) {
            $this->assertSame($group, $surface::getNavigationGroup(), "{$surface} must sit in the Clinic Operations group.");
            $this->assertNotSame('', $surface::getNavigationLabel(), "{$surface} must have a navigation label.");

            $sort = $surface::getNavigationSort();

            $this->assertNotNull($sort, "{$surface} must declare a navigation sort.");
            $sorts[] = $sort;
        }

        $this->assertCount(
            count($sorts),
            array_unique($sorts),
            'Commerce navigation sorts must be unique (no duplicate menu items).',
        );
    }

    public function test_the_clinic_dashboard_lists_the_commerce_group_for_an_entitled_owner(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->createBranch($tenant);

        $response = $this->actingAs($owner)->get('/clinic')->assertOk();

        // The Clinic Operations group and the Commerce dashboard entries render.
        $response->assertSee(__('lba.nav.clinic.operations'));
        $response->assertSee(__('lba.commerce.products.nav'));
        $response->assertSee(__('lba.commerce.orders.nav'));
        $response->assertSee(__('lba.commerce.sales.nav'));
    }
}
