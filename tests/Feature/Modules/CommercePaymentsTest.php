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
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS3 — payment gateways and secure webhooks (focused essentials).
 *
 * Proves the payment invariants: a sandbox charge records a central intent and
 * a pending tenant ledger entry, live charges fail closed unless the master
 * switch is on and the gateway is verified, an unverified webhook is rejected,
 * and a verified webhook settles the order once even when replayed.
 */
class CommercePaymentsTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-synthetic';

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
            'name' => 'Synthetic Payment Product',
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

    private function midtransGateway(bool $enabled = true, array $credentials = []): PaymentGateway
    {
        return PaymentGateway::factory()
            ->provider('midtrans')
            ->when($enabled, fn ($factory) => $factory->enabled())
            ->credentials($credentials ?: ['server_key' => self::SERVER_KEY, 'client_key' => 'SB-Mid-client-synthetic'])
            ->create();
    }

    public function test_a_sandbox_charge_records_an_intent_and_a_pending_ledger_entry(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        $this->midtransGateway();
        [, $order] = $this->placedOrder();

        $initiation = app(PaymentManager::class)->charge($order, 'midtrans');

        $this->assertSame(
            'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            $initiation->redirectUrl,
        );
        $this->assertSame(PaymentIntent::STATUS_PENDING, $initiation->intent->status);
        $this->assertSame('midtrans', $initiation->intent->provider);

        $this->assertDatabaseHas('payment_intents', [
            'provider' => 'midtrans',
            'order_number' => (string) $order->order_number,
        ]);

        // The tenant ledger holds exactly one PENDING attempt.
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(PaymentTransaction::STATUS_PENDING, PaymentTransaction::query()->first()->status);
    }

    public function test_a_live_charge_fails_closed_when_live_payments_are_disabled(): void
    {
        Http::fake();

        $this->midtransGateway(credentials: ['server_key' => 'Mid-server-live-synthetic'])
            ->forceFill([
                'mode' => PaymentGateway::MODE_LIVE,
                'verified_at' => now(),
            ])->save();

        config()->set('payments.live_enabled', false);

        [, $order] = $this->placedOrder();

        $this->expectException(PaymentException::class);

        app(PaymentManager::class)->charge($order, 'midtrans');
    }

    public function test_an_unverified_webhook_is_rejected(): void
    {
        $this->midtransGateway();
        [, $order] = $this->placedOrder();

        $this->postJson('/api/v1/payments/midtrans/webhook', [
            'order_id' => (string) $order->order_number,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'not-the-right-signature',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');
    }

    public function test_a_verified_webhook_settles_the_order_once_even_when_replayed(): void
    {
        $this->midtransGateway();
        [$tenant, $order] = $this->placedOrder(price: 25000, quantity: 2);

        $tenantId = (string) $tenant->getKey();

        // Charge initiation persisted a central intent and left the tenant
        // connection bound; register the intent the way a charge would.
        PaymentIntent::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => (string) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'provider' => 'midtrans',
            'provider_reference' => (string) $order->order_number,
            'currency' => 'IDR',
            'amount' => 50000,
            'status' => PaymentIntent::STATUS_PENDING,
            'idempotency_key' => 'midtrans:intent:'.$order->order_number,
        ]);

        // Release the tenant connection: a real webhook arrives with no context.
        $this->tenantDatabases()->forget();

        $payload = [
            'order_id' => (string) $order->order_number,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY,
        );

        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();

        $this->tenantDatabases()->configure($tenantId);

        $settled = Order::query()->find($order->getKey());
        $this->assertTrue($settled->isPaid());
        $this->assertSame('50000.00', (string) $settled->paid_total);
        $this->assertSame(1, PaymentTransaction::query()->count());

        // Replay the identical notification: still one transaction, still paid.
        $this->tenantDatabases()->forget();

        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();

        $this->tenantDatabases()->configure($tenantId);

        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame('50000.00', (string) Order::query()->find($order->getKey())->paid_total);
    }
}
