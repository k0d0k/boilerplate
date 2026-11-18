<?php

namespace Tests\Feature\Filament;

use App\Filament\Patient\Pages\Booking;
use App\Filament\Patient\Pages\FindLocation;
use App\Filament\Patient\Pages\MyOrders;
use App\Filament\Patient\Pages\Products;
use App\Models\PaymentGateway;
use App\Models\PaymentIntent;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS3 — the patient-facing commerce surface (Produk → Pesanan Saya → sandbox).
 *
 * Exercises the journey that used to be missing from the portal: a bound
 * patient browses the verified clinic's ACTIVE catalog, places an order FOR ITS
 * OWN patient record through the shared {@see OrderService}, sees it on "Pesanan
 * Saya", and starts a hosted SANDBOX payment through the shared
 * {@see PaymentManager}. The order is never settled
 * here — only a verified provider webhook may do that.
 *
 * The security boundary is self-only: a foreign order id is indistinguishable
 * from a missing one (404), an order can never be placed for another patient or
 * another branch, and no clinic context means no shop at all. Only synthetic
 * identities and patients are used (DECISIONS.md PRV-01).
 */
class PatientCommercePortalTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, DrivesPrincipalPanel, RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-synthetic';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Filament::setCurrentPanel(Filament::getPanel('patient'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    private function patientUser(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        return $user;
    }

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

    private function registerPatient(Tenant $tenant, ?User $boundUser, string $name): Patient
    {
        $this->bindTenant($tenant);

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProduct(array $attributes = []): Product
    {
        return app(ProductCatalog::class)->create(array_merge([
            'name' => 'Synthetic Portal Product '.Str::upper(Str::random(4)),
            'base_currency' => 'IDR',
            'base_price' => 15000,
            'track_inventory' => true,
            'status' => Product::STATUS_ACTIVE,
        ], $attributes));
    }

    private function midtransGateway(): PaymentGateway
    {
        return PaymentGateway::factory()
            ->provider('midtrans')
            ->enabled()
            ->credentials(['server_key' => self::SERVER_KEY, 'client_key' => 'SB-Mid-client-synthetic'])
            ->create();
    }

    private function snapshotData(string $html, string $component): array
    {
        $snapshot = json_decode($this->componentSnapshot($html, $component), true);

        return (array) ($snapshot['data'] ?? []);
    }

    // =============================== 1. Catalog (Produk)

    public function test_the_shop_lists_the_active_catalog_and_never_invents_a_price(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Shop Patient');
        $this->createBranch($tenant, ['name' => 'Cabang Toko']);

        $priced = $this->activeProduct(['name' => 'Produk Berharga IDR']);
        $this->activeProduct(['name' => 'Produk Hanya MYR', 'base_currency' => 'MYR', 'base_price' => 75]);
        $this->activeProduct(['name' => 'Produk Arsip', 'status' => Product::STATUS_INACTIVE]);

        $this->actingAs($user)
            ->get('/patient/produk')
            ->assertOk()
            ->assertSee('Produk Berharga IDR')
            ->assertSee('Rp 15,000')
            // Priced only in MYR: shown as unavailable, never guessed.
            ->assertSee('Produk Hanya MYR')
            ->assertSee(__('lba.patient.products.unavailable'))
            // An inactive product is never offered.
            ->assertDontSee('Produk Arsip');

        $this->assertNotNull($priced->getKey());
    }

    // =============================== 2. Place an order (Produk → Pesanan)

    public function test_a_patient_places_an_online_order_for_itself_through_the_shop(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $own = $this->registerPatient($tenant, $user, 'Synthetic Order Patient');
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Pesan']);

        $product = $this->activeProduct(['base_price' => 15000]);
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 10, $owner);

        $this->actingAs($user);

        $html = $this->renderPage('/patient/produk');

        $this->postLivewireCalls($html, Products::class, [
            ['method' => 'submit'],
        ], [
            'branchId' => (string) $branch->getKey(),
            'currency' => 'IDR',
            'quantities.'.(string) $product->getKey() => '2',
            'notes' => 'Uji pesanan portal',
        ])->assertOk();

        $this->bindTenant($tenant);

        $order = Order::query()->firstOrFail();

        // Always the acting identity's OWN patient record, from the online shop.
        $this->assertSame((string) $own->getKey(), (string) $order->patient_id);
        $this->assertSame(Order::SOURCE_ONLINE, $order->source);
        $this->assertSame('IDR', (string) $order->currency);
        $this->assertSame('30000.00', (string) $order->grand_total);

        $this->actingAs($user)
            ->get('/patient/pesanan-saya')
            ->assertOk()
            ->assertSee((string) $order->order_number);
    }

    public function test_the_shop_refuses_a_branch_outside_the_clinic_and_an_empty_cart(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Refuse Patient');
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Sah']);

        $product = $this->activeProduct();
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 10, $owner);

        $this->actingAs($user);

        // A branch id that does not exist in this clinic can never be ordered.
        $this->postLivewireCalls($this->renderPage('/patient/produk'), Products::class, [
            ['method' => 'submit'],
        ], [
            'branchId' => (string) Str::ulid(),
            'currency' => 'IDR',
            'quantities.'.(string) $product->getKey() => '1',
        ])->assertOk();

        // An empty cart is refused too.
        $this->postLivewireCalls($this->renderPage('/patient/produk'), Products::class, [
            ['method' => 'submit'],
        ], [
            'branchId' => (string) $branch->getKey(),
            'currency' => 'IDR',
        ])->assertOk();

        $this->bindTenant($tenant);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_shop_is_denied_without_a_verified_clinic_context(): void
    {
        // A patient-customer identity with NO clinic membership has no context.
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();

        $this->actingAs($user)->get('/patient/produk')->assertForbidden();
        $this->actingAs($user)->get('/patient/pesanan-saya')->assertForbidden();

        $this->assertFalse(Products::canAccess());
        $this->assertFalse(MyOrders::canAccess());
    }

    // =============================== 3. Sandbox payment (Pesanan Saya)

    public function test_a_patient_starts_a_sandbox_payment_for_its_own_open_order(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        $this->midtransGateway();

        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $own = $this->registerPatient($tenant, $user, 'Synthetic Pay Patient');
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Bayar']);

        $product = $this->activeProduct(['base_price' => 25000]);
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 10, $owner);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 2],
        ], [
            'branch_id' => (string) $branch->getKey(),
            'currency' => 'IDR',
            'source' => Order::SOURCE_ONLINE,
            'patient_id' => (string) $own->getKey(),
        ], $owner);

        $this->actingAs($user);

        $html = $this->renderPage('/patient/pesanan-saya');
        $this->assertStringContainsString($order->order_number, $html);

        $response = $this->postLivewireCalls($html, MyOrders::class, [
            ['method' => 'pay', 'params' => [(string) $order->getKey()]],
        ], [
            'providers.'.(string) $order->getKey() => 'midtrans',
        ]);

        $response->assertOk();

        // The hosted redirect is surfaced to the buyer...
        $json = json_decode((string) $response->getContent(), true);
        $rendered = (string) ($json['components'][0]['effects']['html'] ?? '');
        $this->assertStringContainsString('https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token', $rendered);

        // ...a central intent is reserved and the tenant ledger holds one
        // PENDING attempt...
        $this->assertDatabaseHas('payment_intents', [
            'order_number' => (string) $order->order_number,
            'provider' => 'midtrans',
            'status' => PaymentIntent::STATUS_PENDING,
        ]);

        $this->bindTenant($tenant);
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(PaymentTransaction::STATUS_PENDING, PaymentTransaction::query()->first()->status);

        // ...but the order is NOT settled: only a verified webhook may.
        $this->assertFalse(Order::query()->findOrFail($order->getKey())->isPaid());
    }

    public function test_a_foreign_order_cannot_be_paid_through_my_orders(): void
    {
        $this->midtransGateway();

        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Pay Owner');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Pay Foreign');
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Asing Bayar']);

        $product = $this->activeProduct();
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 10, $owner);

        $foreignOrder = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => (string) $branch->getKey(),
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
            'patient_id' => (string) $foreign->getKey(),
        ], $owner);

        $this->actingAs($user);

        // A foreign order id is indistinguishable from a missing one.
        $this->postLivewireCalls($this->renderPage('/patient/pesanan-saya'), MyOrders::class, [
            ['method' => 'pay', 'params' => [(string) $foreignOrder->getKey()]],
        ], [
            'providers.'.(string) $foreignOrder->getKey() => 'midtrans',
        ])->assertNotFound();
    }

    // =============================== 4. Find Location → Booking connector

    public function test_find_location_links_into_booking_carrying_the_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Connector Patient');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Tautan']);

        $this->actingAs($user);

        // The location entry points at Booking with the branch as a hint.
        $url = (new FindLocation)->bookingUrl($branch);
        $this->assertStringContainsString('branch='.(string) $branch->getKey(), $url);

        // The hint is adopted only for an ACTIVE branch of this clinic...
        $data = $this->snapshotData(
            $this->renderPage('/patient/booking?branch='.(string) $branch->getKey()),
            Booking::class,
        );
        $this->assertSame((string) $branch->getKey(), $data['branchId'] ?? null);

        // ...and it can never widen the selectable set.
        $foreign = $this->snapshotData(
            $this->renderPage('/patient/booking?branch='.(string) Str::ulid()),
            Booking::class,
        );
        $this->assertNull($foreign['branchId'] ?? null);
    }
}
