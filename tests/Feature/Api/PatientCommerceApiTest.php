<?php

namespace Tests\Feature\Api;

use App\Models\PaymentGateway;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;
use App\Platform\Api\PatientApiTokenIssuer;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Models\Order;
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
 * WS3 — patient commerce REST API (focused contract + isolation essentials).
 *
 * Proves the mobile shop and checkout: a product is only ever priced in an
 * EXPLICIT currency, a checkout creates a self-scoped order and reserves stock
 * through the same OrderService the clinic uses, and a foreign order id is
 * indistinguishable from a missing one. Only synthetic data is used.
 */
class PatientCommerceApiTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const BASE = '/api/v1/patient';

    private const MIDTRANS_SERVER_KEY = 'SB-Mid-server-synthetic';

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

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function patientUser(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        return $user;
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

    private function issuedToken(User $user, Tenant $tenant): string
    {
        return app(PatientApiTokenIssuer::class)->issue($user, (string) $tenant->getKey(), 'mobile')['plain'];
    }

    /**
     * @return array{0: Tenant, 1: Patient, 2: string, 3: User}
     */
    private function scaffold(int $stock = 10): array
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Buyer');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Jual']);

        return [$tenant, $patient, (string) $branch->getKey(), $user];
    }

    // ========================================= Shop (catalog)

    public function test_the_shop_prices_only_in_the_explicit_currency(): void
    {
        [$tenant, , , $user] = $this->scaffold();

        app(ProductCatalog::class)->create([
            'name' => 'Synthetic IDR Product',
            'base_currency' => 'IDR',
            'base_price' => 25000,
            'status' => 'active',
        ]);

        app(ProductCatalog::class)->create([
            'name' => 'Synthetic MYR Product',
            'base_currency' => 'MYR',
            'base_price' => 12.50,
            'status' => 'active',
        ]);

        app(ProductCatalog::class)->create([
            'name' => 'Synthetic Inactive',
            'base_currency' => 'IDR',
            'base_price' => 9999,
            'status' => 'inactive',
        ]);

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)->getJson(self::BASE.'/shop/products?currency=IDR')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Synthetic IDR Product', $names);
        $this->assertContains('Synthetic MYR Product', $names);
        $this->assertNotContains('Synthetic Inactive', $names);

        $rows = collect($response->json('data'))->keyBy('name');
        $this->assertSame('25000', $rows['Synthetic IDR Product']['price']);
        $this->assertTrue($rows['Synthetic IDR Product']['available']);

        // A product not priced in the requested currency is returned as
        // unavailable rather than guessed.
        $this->assertNull($rows['Synthetic MYR Product']['price']);
        $this->assertFalse($rows['Synthetic MYR Product']['available']);

        // An unsupported currency is rejected with the shared error envelope.
        $this->withToken($token)->getJson(self::BASE.'/shop/products?currency=USD')
            ->assertStatus(422);
    }

    // ========================================= Checkout

    public function test_checkout_creates_an_own_order_and_reserves_stock(): void
    {
        [$tenant, $patient, $branchId, $user] = $this->scaffold(stock: 10);

        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic Checkout Product',
            'base_currency' => 'IDR',
            'base_price' => 25000,
            'status' => 'active',
        ]);

        app(InventoryService::class)->receive($product, $branchId, 10, $this->member($tenant, RoleKey::TENANT_OWNER));

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)->postJson(self::BASE.'/orders', [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'items' => [
                ['product_id' => (string) $product->getKey(), 'quantity' => 3],
            ],
        ])->assertStatus(201);

        $response
            ->assertJsonPath('data.source', Order::SOURCE_API)
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.grand_total', '75000')
            ->assertJsonPath('data.currency', 'IDR');

        $this->assertCount(1, $response->json('data.items'));

        $this->bindTenant($tenant);
        $order = Order::query()->firstOrFail();
        $this->assertSame((string) $patient->getKey(), (string) $order->patient_id);
        $this->assertSame(7, app(InventoryService::class)->level($product, $branchId));
    }

    public function test_a_product_without_a_price_in_the_currency_is_rejected(): void
    {
        [$tenant, , $branchId, $user] = $this->scaffold();

        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic MYR Only',
            'base_currency' => 'MYR',
            'base_price' => 10,
            'status' => 'active',
        ]);

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/orders', [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'items' => [
                ['product_id' => (string) $product->getKey(), 'quantity' => 1],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'order_rejected');
    }

    // ========================================= History (self-only)

    public function test_order_history_and_detail_are_self_only(): void
    {
        [$tenant, $own, $branchId, $user] = $this->scaffold(stock: 20);
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Buyer');

        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic History Product',
            'base_currency' => 'IDR',
            'base_price' => 10000,
            'status' => 'active',
        ]);

        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        app(InventoryService::class)->receive($product, $branchId, 20, $owner);

        $this->bindTenant($tenant);

        $ownOrder = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 2],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
            'patient_id' => (string) $own->getKey(),
        ], $owner);

        $foreignOrder = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
            'patient_id' => (string) $foreign->getKey(),
        ], $owner);

        $token = $this->issuedToken($user, $tenant);

        $list = $this->withToken($token)->getJson(self::BASE.'/orders')->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame((string) $ownOrder->getKey(), (string) $list->json('data.0.id'));

        // A foreign order id is indistinguishable from a missing one.
        $this->withToken($token)->getJson(self::BASE.'/orders/'.$foreignOrder->getKey())
            ->assertStatus(404);

        $this->withToken($token)->getJson(self::BASE.'/orders/'.$ownOrder->getKey())
            ->assertOk()
            ->assertJsonPath('data.order_number', $ownOrder->order_number)
            ->assertJsonPath('data.items.0.quantity', 2);
    }

    public function test_a_user_without_the_order_own_permission_is_denied(): void
    {
        [$tenant] = $this->scaffold();
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

        $this->withToken($token)->getJson(self::BASE.'/orders')->assertStatus(403);
    }

    // ========================================= Sandbox payment → settlement

    public function test_the_journey_settles_only_through_a_verified_webhook(): void
    {
        [$tenant, , $branchId, $user] = $this->scaffold(stock: 10);

        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic Journey Product',
            'base_currency' => 'IDR',
            'base_price' => 25000,
            'status' => 'active',
        ]);

        app(InventoryService::class)->receive($product, $branchId, 10, $this->member($tenant, RoleKey::TENANT_OWNER));

        // An enabled sandbox Midtrans gateway the patient can charge against.
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        PaymentGateway::factory()
            ->provider('midtrans')
            ->enabled()
            ->credentials([
                'server_key' => self::MIDTRANS_SERVER_KEY,
                'client_key' => 'SB-Mid-client-synthetic',
            ])
            ->create();

        $token = $this->issuedToken($user, $tenant);

        // 1. Catalog — the product is visible and priced in the explicit currency.
        $catalog = $this->withToken($token)->getJson(self::BASE.'/shop/products?currency=IDR')->assertOk();
        $row = collect($catalog->json('data'))->firstWhere('name', 'Synthetic Journey Product');
        $this->assertNotNull($row);
        $this->assertTrue($row['available']);
        $this->assertSame('25000', $row['price']);

        // 2. Order — self-scoped, pending.
        $created = $this->withToken($token)->postJson(self::BASE.'/orders', [
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'items' => [['product_id' => (string) $product->getKey(), 'quantity' => 2]],
        ])->assertStatus(201)->assertJsonPath('data.status', Order::STATUS_PENDING);

        $orderId = (string) $created->json('data.id');
        $orderNumber = (string) $created->json('data.order_number');

        // 3. Sandbox payment — hands back the hosted redirect only.
        $this->withToken($token)->postJson(self::BASE.'/orders/'.$orderId.'/pay', ['provider' => 'midtrans'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'midtrans')
            ->assertJsonPath('data.redirect_url', 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token');

        // Initiating a charge must NEVER settle the order.
        $this->withToken($token)->getJson(self::BASE.'/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.paid_total', '0');

        // 4. Only a VERIFIED provider webhook flips the order to paid.
        $this->tenantDatabases()->forget();

        $payload = [
            'order_id' => $orderNumber,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::MIDTRANS_SERVER_KEY,
        );

        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();

        $this->withToken($token)->getJson(self::BASE.'/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_PAID)
            ->assertJsonPath('data.paid_total', '50000');
    }
}
