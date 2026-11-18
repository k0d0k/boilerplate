<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Resources\AiWalletResource;
use App\Filament\Principal\Resources\AiWalletResource\Pages\ListAiWallets;
use App\Filament\Principal\Resources\AiWalletResource\Pages\ViewAiWallet;
use App\Filament\Principal\Resources\AiWalletResource\RelationManagers\AiLedgerRelationManager;
use App\Models\Tenant;
use App\Platform\Access\RoleKey;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Models\TenantAiWallet;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS4 — Principal governance over the central AI (LBA) credit wallets.
 *
 * The wallet engine is central-only and non-identifying (opaque job references
 * and integer credits — never a patient or a clinical finding). This surface is
 * therefore read-only apart from a controlled, idempotent credit grant, and is
 * reachable only through `platform.billing.manage` / `platform.analytics.view`.
 *
 * Interaction is driven through the genuine Livewire round-trip (see
 * {@see DrivesPrincipalPanel}).
 */
class PrincipalAiWalletTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, DrivesPrincipalPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    private function walletFor(Tenant $tenant, int $units = 100): TenantAiWallet
    {
        $service = app(AiWalletService::class);

        $service->grant((string) $tenant->getKey(), $units, 'seed', 'seed-'.Str::ulid()->toBase32());

        $wallet = $service->walletFor((string) $tenant->getKey());

        $this->assertInstanceOf(TenantAiWallet::class, $wallet);

        return $wallet;
    }

    // ------------------------------------------------------- Access control

    public function test_the_wallet_registry_boots_for_an_authorized_principal(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Merdeka']);
        $this->walletFor($tenant, 400);

        $this->actingAs($this->principal())
            ->get('/principal/ai-wallets')
            ->assertOk()
            ->assertSee('Klinik Merdeka');
    }

    public function test_a_principal_without_billing_or_analytics_permission_is_denied(): void
    {
        // brand_manager reaches the Principal panel but holds neither
        // `platform.billing.manage` nor `platform.analytics.view`.
        $brandManager = $this->principal(RoleKey::BRAND_MANAGER);

        $this->actingAs($brandManager)->get('/principal/ai-wallets')->assertForbidden();
    }

    // ------------------------------------------------------------- Granting

    public function test_a_principal_can_grant_credits_through_the_wallet_registry(): void
    {
        $tenant = Tenant::factory()->create();
        $wallet = $this->walletFor($tenant, 100);

        $this->actingAs($this->principal());

        $this->postLivewireCalls($this->renderPage('/principal/ai-wallets'), ListAiWallets::class, [
            ['method' => 'mountAction', 'params' => ['grant', [], ['table' => true, 'recordKey' => $wallet->getKey()]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.units' => 250,
                'mountedActions.0.data.reason' => 'Controlled top-up',
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ])->assertOk();

        $this->assertSame(350, $wallet->refresh()->available_credits);

        // The engine records ONE immutable, non-identifying ledger entry.
        $this->assertDatabaseHas('tenant_ai_wallet_ledger_entries', [
            'wallet_id' => $wallet->getKey(),
            'action' => 'grant',
            'credit_units' => 250,
        ]);
    }

    // -------------------------------------------------------- Immutability

    public function test_wallets_are_read_only_apart_from_the_grant_action(): void
    {
        $tenant = Tenant::factory()->create();
        $wallet = $this->walletFor($tenant);

        $this->assertFalse(AiWalletResource::canCreate());
        $this->assertFalse(AiWalletResource::canEdit($wallet));
        $this->assertFalse(AiWalletResource::canDelete($wallet));
        $this->assertFalse(AiWalletResource::canDeleteAny());
    }

    public function test_the_wallet_view_exposes_its_immutable_ledger(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Ledger']);
        $wallet = $this->walletFor($tenant, 80);

        $html = $this->actingAs($this->principal())
            ->get('/principal/ai-wallets/'.$wallet->getKey())
            ->assertOk()
            ->assertSee('Klinik Ledger')
            ->getContent();

        // The ledger manager lives on the second relation-manager tab
        // (usage, ledger).
        $html = $this->openRelationManagerTab($html, ViewAiWallet::class, 1);

        $this->callRelationManager($html, AiLedgerRelationManager::class, [])->assertOk();
    }
}
