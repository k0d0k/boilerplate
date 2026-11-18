<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
 * WS3 via the Clinic TailAdmin (Blade) surface — point of sale, payment history
 * and the AI credit wallet.
 *
 * These are the AUTHORIZATION and money-path essentials of the TailAdmin
 * counterpart of the Filament POS/transactions/wallet pages: every route and
 * action re-derives, on the server, the module entitlement, the acting
 * permission (order.manage / wallet.manage / tenant.billing.manage), the
 * verified tenant context and the branch scope. A POS checkout writes through
 * the SHARED services, so the domain's transaction and audit rules still hold.
 * Every identity, branch, product and balance here is synthetic.
 */
class ClinicCommerceWalletTest extends TestCase
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

    private function product(): Product
    {
        return app(ProductCatalog::class)->create([
            'name' => 'Synthetic TailAdmin Product',
            'base_currency' => 'IDR',
            'base_price' => 25000,
        ]);
    }

    // ============================== 1. Entitlement and order.manage

    public function test_pos_and_transactions_require_the_module_and_order_manage(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);

        // Module discovered but never activated: deny by default.
        $this->actingAs($owner)->get(route('clinic.tailadmin.pos'))->assertForbidden();
        $this->actingAs($owner)->get(route('clinic.tailadmin.transactions'))->assertForbidden();

        $this->activateModules($tenant, ['commerce']);
        $this->createBranch($tenant);

        // The entitled cashier reaches both surfaces.
        $this->actingAs($owner)->get(route('clinic.tailadmin.pos'))->assertOk();
        $this->actingAs($owner)->get(route('clinic.tailadmin.transactions'))->assertOk();

        // An identity without order.manage is denied outright.
        $this->actingAs($operator)->get(route('clinic.tailadmin.pos'))->assertForbidden();
        $this->actingAs($operator)->get(route('clinic.tailadmin.transactions'))->assertForbidden();
    }

    // ============================== 2. Checkout money path and branch scope

    public function test_pos_checkout_settles_cash_and_branch_scope_is_enforced(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $cashier = $this->member($tenant, RoleKey::COMMERCE_MANAGER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant, ['commerce']);

        $this->tenantDatabases()->configure($tenant->id);
        $branch = $this->createBranch($tenant, ['name' => 'POS Branch']);
        $foreignBranch = $this->createBranch($tenant, ['name' => 'Foreign POS Branch']);
        $this->assignStaff($owner, $cashier, $branch->id);

        $product = $this->product();
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 10, $owner);

        // A branch the cashier may NOT operate in is refused by server validation.
        $this->actingAs($cashier)->post(route('clinic.tailadmin.pos.checkout'), [
            'branch_id' => $foreignBranch->id,
            'currency' => 'IDR',
            'method' => 'cash',
            'idempotency_key' => (string) Str::ulid(),
            'quantities' => [(string) $product->getKey() => 1],
        ])->assertSessionHasErrors('branch_id');

        // The permitted branch settles the sale through the shared services.
        $this->actingAs($cashier)->post(route('clinic.tailadmin.pos.checkout'), [
            'branch_id' => $branch->id,
            'currency' => 'IDR',
            'method' => 'cash',
            'idempotency_key' => (string) Str::ulid(),
            'quantities' => [(string) $product->getKey() => 2],
        ])->assertRedirect(route('clinic.tailadmin.pos'));

        $this->tenantDatabases()->configure($tenant->id);

        $order = Order::query()->firstOrFail();
        $this->assertSame(Order::SOURCE_POS, $order->source);
        $this->assertSame('50000.00', (string) $order->grand_total);
        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(8, app(InventoryService::class)->level($product, (string) $branch->getKey()));
    }

    // ============================== 3. Wallet permission and live balance

    public function test_wallet_requires_permission_and_shows_actual_balance(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant, ['lba']);

        // An identity without wallet/billing permission never reaches the wallet.
        $this->actingAs($operator)->get(route('clinic.tailadmin.wallet'))->assertForbidden();

        // The verified tenant's actual balance is shown, not a sample figure.
        app(AiWalletService::class)->grant((string) $tenant->getKey(), 250, 'Synthetic test balance', (string) Str::ulid());

        $this->actingAs($owner)->get(route('clinic.tailadmin.wallet'))
            ->assertOk()
            ->assertSee(number_format(250))
            ->assertSee((string) $tenant->getKey());
    }
}
