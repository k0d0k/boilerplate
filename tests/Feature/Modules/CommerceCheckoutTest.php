<?php

namespace Tests\Feature\Modules;

use App\Models\PaymentGateway;
use App\Models\PaymentIntent;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;
use App\Platform\Api\PatientApiTokenIssuer;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Filament\Pages\PosTerminal;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS8.B — POS, patient checkout and safe repeat actions (focused essentials).
 *
 * Proves the checkout contract end to end without any real money:
 *  - a repeated checkout (double-click / retried request) with the same
 *    idempotency key returns the ORIGINAL order and never reserves stock twice;
 *  - a provider tender keeps the order OPEN and only surfaces the hosted
 *    redirect — a browser return can never mark an order paid;
 *  - a provider tender is refused unless the platform offers a currently
 *    chargeable sandbox gateway for the cart currency;
 *  - a patient can start a sandbox charge ONLY for their own order, a foreign
 *    order id is a 404, and an unavailable provider is rejected.
 *
 * Only synthetic products, tenants and sandbox credentials are used. Live
 * payments are never enabled.
 */
class CommerceCheckoutTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'commerce';

    private const SERVER_KEY = 'SB-Mid-server-synthetic';

    private const BASE = '/api/v1/patient';

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

    private function midtransGateway(): PaymentGateway
    {
        return PaymentGateway::factory()
            ->provider('midtrans')
            ->enabled()
            ->credentials(['server_key' => self::SERVER_KEY, 'client_key' => 'SB-Mid-client-synthetic'])
            ->create();
    }

    /**
     * A tenant, an owner, an activated commerce module, a branch and one
     * stocked, inventory-tracked product.
     *
     * @return array{0: Tenant, 1: User, 2: Product, 3: string}
     */
    private function scaffold(float $price = 25000, int $stock = 10): array
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);

        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic Checkout Product',
            'base_currency' => 'IDR',
            'base_price' => $price,
        ]);

        app(InventoryService::class)->receive($product, (string) $branch->getKey(), $stock, $owner);

        return [$tenant, $owner, $product, (string) $branch->getKey()];
    }

    // ========================================= Idempotent checkout

    public function test_repeating_a_checkout_returns_the_same_order_without_double_reserving_stock(): void
    {
        [$tenant, $owner, $product, $branchId] = $this->scaffold(stock: 10);

        $lines = [['product_id' => (string) $product->getKey(), 'quantity' => 2]];
        $context = [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
            'idempotency_key' => 'pos:01J0SYNTHETICCHECKOUTKEY',
        ];

        $first = app(OrderService::class)->place($lines, $context, $owner);
        $second = app(OrderService::class)->place($lines, $context, $owner);

        $this->assertSame((string) $first->getKey(), (string) $second->getKey());

        $this->tenantDatabases()->configure((string) $tenant->getKey());

        // Exactly one order and exactly one reservation (10 - 2, not 10 - 4).
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(8, app(InventoryService::class)->level($product, $branchId));
    }

    public function test_a_checkout_without_a_key_places_a_distinct_order_each_time(): void
    {
        [$tenant, $owner, $product, $branchId] = $this->scaffold(stock: 10);

        $lines = [['product_id' => (string) $product->getKey(), 'quantity' => 1]];
        $context = [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
        ];

        app(OrderService::class)->place($lines, $context, $owner);
        app(OrderService::class)->place($lines, $context, $owner);

        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $this->assertSame(2, Order::query()->count());
        $this->assertSame(8, app(InventoryService::class)->level($product, $branchId));
    }

    // ========================================= POS provider tender

    public function test_a_pos_provider_tender_leaves_the_order_open_and_surfaces_a_redirect(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        [$tenant, $owner, $product, $branchId] = $this->scaffold();
        $this->midtransGateway();

        $this->actingAs($owner);
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $page = new PosTerminal;
        $page->branchId = $branchId;
        $page->currency = 'IDR';
        $page->paymentMethod = PosTerminal::METHOD_PROVIDER;
        $page->provider = 'midtrans';
        $page->addProduct((string) $product->getKey(), 2);

        $this->assertTrue($page->canCheckout());

        $page->checkout();

        $this->assertSame(
            'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            $page->lastRedirectUrl,
        );

        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $order = Order::query()->firstOrFail();

        // A provider tender NEVER marks the order paid from the browser.
        $this->assertFalse($order->isPaid());
        $this->assertTrue($order->isOpen());

        // The central intent and a PENDING tenant ledger entry were recorded.
        $this->assertDatabaseHas('payment_intents', [
            'provider' => 'midtrans',
            'order_number' => (string) $order->order_number,
            'status' => PaymentIntent::STATUS_PENDING,
        ]);
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(PaymentTransaction::STATUS_PENDING, PaymentTransaction::query()->first()->status);
    }

    public function test_a_pos_provider_tender_requires_a_chargeable_gateway(): void
    {
        [$tenant, $owner, $product, $branchId] = $this->scaffold();

        $this->actingAs($owner);
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $page = new PosTerminal;
        $page->branchId = $branchId;
        $page->currency = 'IDR';
        $page->paymentMethod = PosTerminal::METHOD_PROVIDER;
        $page->addProduct((string) $product->getKey(), 1);

        // No gateway row at all: nothing is offered and checkout is refused.
        $page->provider = 'midtrans';
        $this->assertSame([], $page->providerOptions());
        $this->assertFalse($page->canCheckout());

        // An unselected provider is likewise refused.
        $page->provider = null;
        $this->assertFalse($page->canCheckout());
    }

    // ========================================= Patient pay (self-scoped)

    public function test_a_patient_can_start_a_sandbox_charge_for_their_own_order(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        [$tenant, $patient, $branchId, $user] = $this->patientScaffold();
        $this->midtransGateway();

        $product = $this->patientProduct('IDR', 25000);
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        app(InventoryService::class)->receive($product, $branchId, 10, $owner);

        $this->bindTenant($tenant);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 2],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_API,
            'patient_id' => (string) $patient->getKey(),
        ], $owner);

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)
            ->postJson(self::BASE.'/orders/'.$order->getKey().'/pay', ['provider' => 'midtrans'])
            ->assertOk();

        $response
            ->assertJsonPath('data.provider', 'midtrans')
            ->assertJsonPath('data.status', PaymentIntent::STATUS_PENDING)
            ->assertJsonPath('data.redirect_url', 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token');

        $this->bindTenant($tenant);

        // Starting a charge never settles the order on its own.
        $this->assertFalse(Order::query()->find($order->getKey())->isPaid());
    }

    public function test_a_patient_cannot_pay_a_foreign_order(): void
    {
        [$tenant, $patient, $branchId, $user] = $this->patientScaffold();
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Buyer');
        $this->midtransGateway();

        $product = $this->patientProduct('IDR', 25000);
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        app(InventoryService::class)->receive($product, $branchId, 10, $owner);

        $this->bindTenant($tenant);

        $foreignOrder = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_API,
            'patient_id' => (string) $foreign->getKey(),
        ], $owner);

        $token = $this->issuedToken($user, $tenant);

        // A foreign order id is indistinguishable from a missing one.
        $this->withToken($token)
            ->postJson(self::BASE.'/orders/'.$foreignOrder->getKey().'/pay', ['provider' => 'midtrans'])
            ->assertStatus(404);

        $this->bindTenant($tenant);
        $this->assertFalse(Order::query()->find($foreignOrder->getKey())->isPaid());
    }

    public function test_a_patient_payment_is_rejected_when_the_provider_is_unavailable(): void
    {
        [$tenant, $patient, $branchId, $user] = $this->patientScaffold();

        $product = $this->patientProduct('IDR', 25000);
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        app(InventoryService::class)->receive($product, $branchId, 10, $owner);

        $this->bindTenant($tenant);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_API,
            'patient_id' => (string) $patient->getKey(),
        ], $owner);

        // No gateway row exists, so nothing may be charged.
        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)
            ->postJson(self::BASE.'/orders/'.$order->getKey().'/pay', ['provider' => 'midtrans'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_unavailable');
    }

    // ------------------------------------------------- Patient helpers

    private function patientScaffold(): array
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        $this->bindTenant($tenant);

        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Buyer',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $owner);
        app(PatientAccountLinker::class)->redeemInvitation($user, $code);

        $branch = $this->createBranch($tenant, ['name' => 'Cabang Jual']);

        return [$tenant, $patient, (string) $branch->getKey(), $user];
    }

    private function registerPatient(Tenant $tenant, ?User $boundUser, string $name): Patient
    {
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => $name,
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        if ($boundUser !== null) {
            $this->bindTenant($tenant);
            $code = app(PatientAccountLinker::class)->issueInvitation($patient, $owner);
            app(PatientAccountLinker::class)->redeemInvitation($boundUser, $code);
        }

        return $patient;
    }

    private function patientProduct(string $currency, float $price): Product
    {
        return app(ProductCatalog::class)->create([
            'name' => 'Synthetic '.$currency.' Product',
            'base_currency' => $currency,
            'base_price' => $price,
            'status' => 'active',
        ]);
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function issuedToken(User $user, Tenant $tenant): string
    {
        return app(PatientApiTokenIssuer::class)->issue($user, (string) $tenant->getKey(), 'mobile')['plain'];
    }

    public function test_the_patient_pay_route_requires_the_order_own_permission(): void
    {
        [$tenant] = $this->patientScaffold();

        // A clinic role that holds no patient-facing order permission.
        $user = $this->member($tenant, RoleKey::LBA_OPERATOR);

        $this->assertFalse(
            app(ChecksPermissions::class)->allows(
                $user,
                PermissionKey::ORDER_OWN,
                AccessContext::forTenant((string) $tenant->getKey(), null),
            ),
        );

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)
            ->postJson(self::BASE.'/orders/01SYNTHETICORDERID/pay', ['provider' => 'midtrans'])
            ->assertStatus(403);
    }
}
