<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Resources\PaymentGatewayResource;
use App\Filament\Principal\Resources\PaymentGatewayResource\Pages\CreatePaymentGateway;
use App\Filament\Principal\Resources\PaymentGatewayResource\Pages\EditPaymentGateway;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS3 — Principal payment gateway configuration (server layer).
 *
 * Exercises the real Filament surface and asserts the two safety invariants
 * directly against storage: credentials are encrypted at rest and are never
 * pre-filled, and a principal without `platform.payment.manage` is refused.
 */
class PrincipalPaymentConfigurationTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, DrivesPrincipalPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    public function test_the_payment_registry_boots_for_an_authorized_principal(): void
    {
        PaymentGateway::factory()->create(['label' => 'Midtrans Production', 'provider' => 'midtrans']);

        $this->actingAs($this->principal())
            ->get('/principal/payment-gateways')
            ->assertOk()
            ->assertSee('Midtrans Production');
    }

    public function test_a_principal_without_the_payment_permission_is_denied(): void
    {
        // principal_module_admin reaches the panel but holds no
        // `platform.payment.manage`, so the resource must refuse it.
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN))
            ->get('/principal/payment-gateways')
            ->assertForbidden();
    }

    public function test_a_clinic_identity_cannot_reach_the_payment_registry(): void
    {
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->actingAs($owner)->get('/principal/payment-gateways')->assertForbidden();
    }

    public function test_a_principal_can_configure_a_gateway_with_encrypted_credentials(): void
    {
        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/payment-gateways/create');

        $this->postLivewireCalls($html, CreatePaymentGateway::class, [
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'data.provider' => 'midtrans',
                'data.label' => 'Midtrans Sandbox',
                'data.mode' => PaymentGateway::MODE_SANDBOX,
                'data.enabled' => true,
                'data.credential_server_key' => 'SB-Mid-server-secret',
                'data.currencies' => ['IDR', 'MYR'],
            ], 'data']],
            ['method' => 'create', 'params' => []],
        ])->assertOk();

        $gateway = PaymentGateway::query()->where('provider', 'midtrans')->firstOrFail();

        $this->assertTrue($gateway->enabled);
        $this->assertSame(['IDR', 'MYR'], $gateway->currencies);
        // Decrypted on access through the model...
        $this->assertSame('SB-Mid-server-secret', $gateway->credential('server_key'));

        // ...but NEVER stored as plaintext.
        $raw = (string) DB::table('payment_gateways')->where('provider', 'midtrans')->value('credentials');
        $this->assertStringNotContainsString('SB-Mid-server-secret', $raw);
    }

    public function test_editing_a_gateway_keeps_an_unchanged_secret(): void
    {
        $gateway = PaymentGateway::factory()
            ->provider('midtrans')
            ->enabled()
            ->credentials(['server_key' => 'SB-Mid-server-original'])
            ->create();

        $this->actingAs($this->principal());

        $html = $this->renderPage('/principal/payment-gateways/'.$gateway->getKey().'/edit');

        // The secret field is never pre-filled.
        $this->assertStringNotContainsString('SB-Mid-server-original', $html);

        $this->postLivewireCalls($html, EditPaymentGateway::class, [
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'data.label' => 'Midtrans Renamed',
            ], 'data']],
            ['method' => 'save', 'params' => []],
        ])->assertOk();

        $gateway->refresh();

        $this->assertSame('Midtrans Renamed', $gateway->label);
        $this->assertSame('SB-Mid-server-original', $gateway->credential('server_key'));
    }

    public function test_gateways_are_never_deletable(): void
    {
        $gateway = PaymentGateway::factory()->create();

        $this->assertFalse(PaymentGatewayResource::canDelete($gateway));
        $this->assertFalse(PaymentGatewayResource::canDeleteAny());
    }
}
