<?php

namespace Tests\Feature\Modules;

use App\Models\PaymentGateway;
use App\Models\PaymentIntent;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS3 — the PayPal and iPaymu adapters (focused essentials).
 *
 * The Midtrans adapter is exercised by {@see CommercePaymentsTest}; this suite
 * proves the SAME contract invariants for the other two gateways the platform
 * ships:
 *
 *  - a sandbox charge creates the hosted redirect and records a central intent
 *    plus a pending tenant ledger entry, WITHOUT the secret ever being echoed;
 *  - a webhook whose origin cannot be verified is rejected (PayPal signature
 *    verification, iPaymu notify signature);
 *  - a verified webhook settles the order exactly once, and a REPLAY of the
 *    identical notification is a no-op.
 *
 * Every identity, order and gateway here is synthetic (DECISIONS.md PRV-01).
 */
class CommerceGatewayDriversTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const PAYPAL_CLIENT_ID = 'AY-synthetic-client';

    private const PAYPAL_CLIENT_SECRET = 'EL-synthetic-secret';

    private const IPAYMU_API_KEY = 'ipaymu-synthetic-key';

    private const IPAYMU_VA = '0000001234567890';

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
     * @return array{0: Tenant, 1: Order}
     */
    private function placedOrder(float $price = 25000, int $quantity = 2, int $stock = 10): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($actor, $tenant, [RoleKey::TENANT_OWNER]);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic Gateway Product',
            'base_currency' => 'IDR',
            'base_price' => $price,
        ]);

        app(InventoryService::class)->receive($product, (string) $branch->getKey(), $stock, $actor);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => $quantity],
        ], [
            'branch_id' => (string) $branch->getKey(),
            'currency' => 'IDR',
            'source' => Order::SOURCE_ONLINE,
        ], $actor);

        return [$tenant, $order];
    }

    private function paypalGateway(bool $enabled = true, ?string $webhookId = 'WH-synthetic'): PaymentGateway
    {
        return PaymentGateway::factory()
            ->provider('paypal')
            ->when($enabled, fn ($factory) => $factory->enabled())
            ->credentials([
                'client_id' => self::PAYPAL_CLIENT_ID,
                'client_secret' => self::PAYPAL_CLIENT_SECRET,
            ])
            ->create(['config' => ['webhook_id' => $webhookId]]);
    }

    private function ipaymuGateway(bool $enabled = true): PaymentGateway
    {
        return PaymentGateway::factory()
            ->provider('ipaymu')
            ->when($enabled, fn ($factory) => $factory->enabled())
            ->credentials([
                'api_key' => self::IPAYMU_API_KEY,
                'va' => self::IPAYMU_VA,
            ])
            ->create();
    }

    // ==================================================== 1. Sandbox charges

    public function test_a_paypal_sandbox_charge_creates_the_hosted_order_and_a_pending_ledger(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok-1'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAY-ORDER-1',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAY-ORDER-1'],
                ],
            ], 201),
        ]);

        $this->paypalGateway();
        [, $order] = $this->placedOrder();

        $initiation = app(PaymentManager::class)->charge($order, 'paypal');

        $this->assertSame(
            'https://www.sandbox.paypal.com/checkoutnow?token=PAY-ORDER-1',
            $initiation->redirectUrl,
        );
        $this->assertSame(PaymentIntent::STATUS_PENDING, $initiation->intent->status);
        $this->assertSame('paypal', $initiation->intent->provider);

        // The tenant ledger holds exactly one PENDING attempt; the secret never
        // appears in the recorded payload.
        $this->assertSame(1, PaymentTransaction::query()->count());
        $transaction = PaymentTransaction::query()->firstOrFail();
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->status);
        $this->assertStringNotContainsString(self::PAYPAL_CLIENT_SECRET, json_encode($transaction->response_payload));
    }

    public function test_an_ipaymu_sandbox_charge_creates_the_hosted_payment_and_a_pending_ledger(): void
    {
        Http::fake([
            'sandbox.ipaymu.com/api/v2/payment' => Http::response([
                'Data' => [
                    'SessionID' => 'SID-1',
                    'Url' => 'https://sandbox.ipaymu.com/payment/SID-1',
                ],
            ], 200),
        ]);

        $this->ipaymuGateway();
        [, $order] = $this->placedOrder();

        $initiation = app(PaymentManager::class)->charge($order, 'ipaymu');

        $this->assertSame('https://sandbox.ipaymu.com/payment/SID-1', $initiation->redirectUrl);
        $this->assertSame(PaymentIntent::STATUS_PENDING, $initiation->intent->status);
        $this->assertSame('ipaymu', $initiation->intent->provider);

        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(PaymentTransaction::STATUS_PENDING, PaymentTransaction::query()->first()->status);
    }

    // ==================================================== 2. Webhook rejection

    public function test_a_paypal_webhook_without_a_configured_webhook_id_is_rejected(): void
    {
        Http::fake();

        $this->paypalGateway(webhookId: null);
        [, $order] = $this->placedOrder();

        $this->postJson('/api/v1/payments/paypal/webhook', [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAPTURE-1'],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');

        Http::assertNothingSent();
    }

    public function test_a_paypal_webhook_whose_signature_verification_fails_is_rejected(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok-1'], 200),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ], 200),
        ]);

        $this->paypalGateway();
        [, $order] = $this->placedOrder();

        $this->postJson('/api/v1/payments/paypal/webhook', [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAPTURE-1'],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');

        $this->assertSame(0, PaymentTransaction::query()->count());
    }

    public function test_an_ipaymu_webhook_with_a_bad_signature_is_rejected(): void
    {
        $this->ipaymuGateway();
        [, $order] = $this->placedOrder();

        $this->postJson('/api/v1/payments/ipaymu/webhook', [
            'referenceId' => (string) $order->order_number,
            'status' => 'success',
            'statusCode' => '200',
            'amount' => '50000',
            'currency' => 'IDR',
            'signature' => 'not-the-right-signature',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');

        $this->assertSame(0, PaymentTransaction::query()->count());
    }

    // ==================================== 3. Verified webhook = idempotent settle

    public function test_a_verified_paypal_webhook_settles_the_order_once_even_when_replayed(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok-1'], 200),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $this->paypalGateway();
        [$tenant, $order] = $this->placedOrder(price: 25000, quantity: 2);
        $tenantId = (string) $tenant->getKey();

        PaymentIntent::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => (string) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'provider' => 'paypal',
            'provider_reference' => 'CAPTURE-1',
            'currency' => 'IDR',
            'amount' => 50000,
            'status' => PaymentIntent::STATUS_PENDING,
            'idempotency_key' => 'paypal:intent:'.$order->order_number,
        ]);

        // A real webhook arrives with no bound tenant.
        $this->tenantDatabases()->forget();

        $payload = [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAPTURE-1',
                'amount' => ['value' => '50000.00', 'currency_code' => 'IDR'],
            ],
        ];

        $this->postJson('/api/v1/payments/paypal/webhook', $payload)->assertOk();

        $this->tenantDatabases()->configure($tenantId);
        $settled = Order::query()->find($order->getKey());
        $this->assertTrue($settled->isPaid());
        $this->assertSame('50000.00', (string) $settled->paid_total);
        $this->assertSame(1, PaymentTransaction::query()->count());

        // Replay the identical notification: still one transaction, still paid.
        $this->tenantDatabases()->forget();

        $this->postJson('/api/v1/payments/paypal/webhook', $payload)->assertOk();

        $this->tenantDatabases()->configure($tenantId);
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame('50000.00', (string) Order::query()->find($order->getKey())->paid_total);
    }

    public function test_a_verified_ipaymu_webhook_settles_the_order_once_even_when_replayed(): void
    {
        $this->ipaymuGateway();
        [$tenant, $order] = $this->placedOrder(price: 25000, quantity: 2);
        $tenantId = (string) $tenant->getKey();

        PaymentIntent::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => (string) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'provider' => 'ipaymu',
            'provider_reference' => (string) $order->order_number,
            'currency' => 'IDR',
            'amount' => 50000,
            'status' => PaymentIntent::STATUS_PENDING,
            'idempotency_key' => 'ipaymu:intent:'.$order->order_number,
        ]);

        $referenceId = (string) $order->order_number;
        $status = 'success';
        $statusCode = '200';

        $payload = [
            'referenceId' => $referenceId,
            'status' => $status,
            'statusCode' => $statusCode,
            'amount' => '50000',
            'currency' => 'IDR',
            'signature' => hash('sha256', self::IPAYMU_VA.self::IPAYMU_API_KEY.$referenceId.$status.$statusCode),
        ];

        $this->tenantDatabases()->forget();

        $this->postJson('/api/v1/payments/ipaymu/webhook', $payload)->assertOk();

        $this->tenantDatabases()->configure($tenantId);
        $settled = Order::query()->find($order->getKey());
        $this->assertTrue($settled->isPaid());
        $this->assertSame('50000.00', (string) $settled->paid_total);
        $this->assertSame(1, PaymentTransaction::query()->count());

        // Replay the identical notification: still one transaction, still paid.
        $this->tenantDatabases()->forget();

        $this->postJson('/api/v1/payments/ipaymu/webhook', $payload)->assertOk();

        $this->tenantDatabases()->configure($tenantId);
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame('50000.00', (string) Order::query()->find($order->getKey())->paid_total);
    }
}
