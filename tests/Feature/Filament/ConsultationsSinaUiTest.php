<?php

namespace Tests\Feature\Filament;

use App\Filament\Patient\Pages\MyConsultations;
use App\Filament\Patient\Pages\SinaChat;
use App\Filament\Principal\Pages\AiProviderSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Consultations\Models\AiProviderConfig;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\SinaWalletService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * Workstream 2 — consultation + SINA Filament surfaces (focused essentials).
 *
 * Covers the server-enforced boundaries of the three WS2 platform pages:
 *  - Principal "AI Provider Configuration": `platform.billing.manage` gating,
 *    configuration persistence, credential encryption and endpoint policy;
 *  - Patient "Konsultasi Saya": self-only, fail-closed consultation history;
 *  - Patient SINA chat: the explicit `ai_assistance` consent gate and session
 *    start.
 *
 * UI visibility is never the authorization boundary; every page re-derives its
 * decision server side. Only synthetic identities/patients are used.
 */
class ConsultationsSinaUiTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsPrincipalGovernance, BuildsTenantDatabases, DrivesPrincipalPanel, RefreshDatabase;

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

    private function patientUser(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

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
            $this->bindPatient($tenant, $patient, $boundUser, $owner);
        }

        return $patient;
    }

    /**
     * Bind a portal account the way production does: an authorized clinic
     * identity issues a single-use invitation proof, which the account redeems.
     */
    private function bindPatient(Tenant $tenant, Patient $patient, User $account, User $actor): void
    {
        $this->bindTenant($tenant);

        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $actor);

        app(PatientAccountLinker::class)->redeemInvitation($account, $code);
    }

    /**
     * Issue one Livewire round-trip against an explicit snapshot, applying
     * property updates BEFORE the given ordered method calls — exactly the
     * order a browser produces.
     *
     * @param  array<string, mixed>  $updates
     * @param  list<array{method: string, params?: list<mixed>}>  $calls
     */
    private function postSnapshot(string $snapshot, array $updates = [], array $calls = []): TestResponse
    {
        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'calls' => array_map(
                    static fn (array $call): array => [
                        'method' => $call['method'],
                        'params' => $call['params'] ?? [],
                        'path' => '',
                    ],
                    $calls,
                ),
                'updates' => $updates,
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /**
     * The (re-rendered) component snapshot carried by a Livewire response.
     */
    private function snapshotFrom(TestResponse $response, int $index = 0): string
    {
        $component = json_decode((string) $response->getContent(), true)['components'][$index] ?? [];
        $snapshot = $component['snapshot'] ?? '';

        return is_array($snapshot) ? (string) json_encode($snapshot) : (string) $snapshot;
    }

    /**
     * Select a (tenant, provider) on the AI provider page and return the
     * re-rendered snapshot — the hook resets the fields, so a second round-trip
     * is required before saving (exactly as the browser behaves).
     */
    private function selectAiProviderRow(string $html, Tenant $tenant, string $provider): string
    {
        $response = $this->postSnapshot(
            $this->componentSnapshot($html, AiProviderSettings::class),
            [
                'tenantId' => (string) $tenant->getKey(),
                'provider' => $provider,
            ],
        );

        $response->assertOk();

        return $this->snapshotFrom($response);
    }

    // ====================================== 1. Principal AI provider config

    public function test_the_ai_provider_page_boots_for_a_billing_principal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        Tenant::factory()->create(['name' => 'Klinik Sinar']);

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))
            ->get('/principal/ai-providers')
            ->assertOk()
            ->assertSee('Klinik Sinar');
    }

    public function test_a_principal_without_the_billing_permission_is_denied(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        // principal_module_admin holds module/entitlement authority, not billing.
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN))
            ->get('/principal/ai-providers')
            ->assertForbidden();

        $this->assertFalse(AiProviderSettings::canAccess());
    }

    public function test_saving_a_configuration_encrypts_the_credential_and_never_echoes_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        $tenant = Tenant::factory()->create();
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));

        $html = $this->renderPage('/principal/ai-providers');

        $snapshot = $this->selectAiProviderRow($html, $tenant, AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE);

        $this->postSnapshot($snapshot, [
            'baseUrl' => 'https://sina.test/v1',
            'model' => 'test-sina-model',
            'apiKey' => 'top-secret-credential',
            'enabled' => true,
        ], [
            ['method' => 'save'],
        ])->assertOk();

        $config = AiProviderConfig::query()->where('tenant_id', $tenant->getKey())->firstOrFail();

        $this->assertSame('https://sina.test/v1', $config->base_url);
        $this->assertSame('test-sina-model', $config->model);
        $this->assertTrue($config->enabled);
        $this->assertTrue($config->is_default);

        // The credential is never stored in plaintext.
        $raw = (string) DB::connection((string) config('tenancy.central_connection'))
            ->table('ai_provider_configs')
            ->where('id', $config->getKey())
            ->value('api_key');

        $this->assertNotSame('top-secret-credential', $raw);
        $this->assertSame('top-secret-credential', $config->fresh()->api_key);

        // …and never echoed back into the rendered page.
        $this->get('/principal/ai-providers')
            ->assertOk()
            ->assertDontSee('top-secret-credential');
    }

    public function test_an_insecure_endpoint_is_rejected_and_nothing_is_persisted(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        $tenant = Tenant::factory()->create();
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));

        $html = $this->renderPage('/principal/ai-providers');

        $snapshot = $this->selectAiProviderRow($html, $tenant, AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE);

        $this->postSnapshot($snapshot, [
            'baseUrl' => 'http://insecure.example.com/v1',
            'model' => 'test-sina-model',
        ], [
            ['method' => 'save'],
        ])->assertOk();

        $this->assertDatabaseMissing('ai_provider_configs', [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    public function test_a_loopback_http_endpoint_is_accepted_for_a_local_ollama(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        $tenant = Tenant::factory()->create();
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));

        $html = $this->renderPage('/principal/ai-providers');

        $snapshot = $this->selectAiProviderRow($html, $tenant, AiProviderConfig::PROVIDER_OLLAMA);

        $this->postSnapshot($snapshot, [
            'baseUrl' => 'http://localhost:11434',
            'model' => 'llama-test',
        ], [
            ['method' => 'save'],
        ])->assertOk();

        $this->assertDatabaseHas('ai_provider_configs', [
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OLLAMA,
            'base_url' => 'http://localhost:11434',
        ]);
    }

    public function test_saving_without_a_new_credential_preserves_the_stored_one(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        $tenant = Tenant::factory()->create();

        AiProviderConfig::query()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'base_url' => 'https://existing.test/v1',
            'model' => 'existing-model',
            'api_key' => 'existing-secret',
            'is_default' => true,
            'enabled' => true,
        ]);

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));

        $html = $this->renderPage('/principal/ai-providers');

        // Selecting the tenant loads the stored row; the write-only credential
        // field stays empty, so saving must not overwrite the stored secret.
        $snapshot = $this->selectAiProviderRow($html, $tenant, AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE);

        $this->postSnapshot($snapshot, [], [
            ['method' => 'save'],
        ])->assertOk();

        $config = AiProviderConfig::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('provider', AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE)
            ->firstOrFail();

        $this->assertSame('existing-secret', $config->api_key);
        $this->assertSame('existing-model', $config->model);
    }

    // ============================================ 2. Patient consultation history

    public function test_the_consultations_page_is_denied_without_a_verified_clinic_context(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();

        $this->actingAs($user)->get('/patient/konsultasi-saya')->assertForbidden();
    }

    public function test_my_consultations_lists_only_the_acting_patients_own_consultations(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Consultation');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Consultation');

        $ownBranch = $this->createBranch($tenant, ['name' => 'Cabang Sendiri']);

        // The foreign branch is inactive: it is neither offered in the booking
        // selector (active branches only) nor reachable through the actor's own
        // consultation list, so its name must never appear on the page.
        $foreignBranch = $this->createBranch($tenant, [
            'name' => 'Cabang Asing',
            'status' => TenantBranch::STATUS_INACTIVE,
        ]);

        $manager = app(ConsultationManager::class);

        $manager->create([
            'patient_id' => $own->getKey(),
            'branch_id' => $ownBranch->getKey(),
            'channel' => Consultation::CHANNEL_SINA_CHAT,
            'type' => Consultation::TYPE_CONSULTATION,
        ], $owner);

        $manager->create([
            'patient_id' => $foreign->getKey(),
            'branch_id' => $foreignBranch->getKey(),
            'channel' => Consultation::CHANNEL_SINA_CHAT,
            'type' => Consultation::TYPE_CONSULTATION,
        ], $owner);

        $this->actingAs($user)
            ->get('/patient/konsultasi-saya')
            ->assertOk()
            ->assertSee('Cabang Sendiri')
            ->assertDontSee('Cabang Asing');
    }

    public function test_the_token_wallet_shows_only_the_acting_patients_balance_and_usage(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = $this->patientUser($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Wallet Patient');
        $otherPatient = $this->registerPatient($tenant, null, 'Synthetic Other Wallet Patient');
        $wallets = app(SinaWalletService::class);

        foreach ([
            [$tenant->getKey(), $patient->getKey(), 117, 13],
            [$tenant->getKey(), $otherPatient->getKey(), 245, 23],
            [$otherTenant->getKey(), $patient->getKey(), 374, 43],
        ] as $index => [$tenantId, $patientId, $balance, $charge]) {
            $consultationId = (string) Str::ulid();

            $wallets->grant($tenantId, $patientId, $balance, 'Synthetic grant', 'wallet-grant-'.$index);
            $wallets->reserve($tenantId, $patientId, $consultationId, $charge, 'wallet-reserve-'.$index);
            $wallets->capture($tenantId, $patientId, $consultationId, $charge, 'wallet-capture-'.$index);
        }

        $this->actingAs($user)->get('/patient/dompet-token')
            ->assertOk()
            ->assertSeeText('104')
            ->assertSeeText(__('patient_wallet.credits_used', ['credits' => 13]))
            ->assertDontSeeText(__('patient_wallet.credits_used', ['credits' => 23]))
            ->assertDontSeeText(__('patient_wallet.credits_used', ['credits' => 43]));
    }

    public function test_reading_the_token_wallet_does_not_create_a_wallet(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = $this->patientUser($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Empty Wallet Patient');

        $this->actingAs($user)->get('/patient/dompet-token')
            ->assertOk()
            ->assertSeeText(__('patient_wallet.no_wallet'))
            ->assertSeeText(__('patient_wallet.no_usage'));

        $this->assertNull(app(SinaWalletService::class)->walletFor($tenant->getKey(), $patient->getKey()));
        $this->actingAs(User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create())
            ->get('/patient/dompet-token')->assertForbidden();
    }

    // ==================================================== 3. Patient SINA chat

    public function test_the_sina_chat_is_denied_without_a_verified_clinic_context(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();

        $this->actingAs($user)->get('/patient/sina')->assertForbidden();
    }

    public function test_granting_consent_records_the_consent_and_the_audit_trail(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic SINA Patient');

        $this->actingAs($user);
        $this->bindTenant($tenant);

        (new SinaChat)->grantConsent();

        $this->bindTenant($tenant);

        $this->assertTrue(PatientConsent::query()
            ->where('patient_id', $patient->getKey())
            ->where('consent_type', PatientConsent::TYPE_AI_ASSISTANCE)
            ->where('status', PatientConsent::STATUS_GRANTED)
            ->exists());

        $this->assertTrue(PatientAuditLog::query()
            ->where('patient_id', $patient->getKey())
            ->where('action', PatientAuditLog::ACTION_CONSENT_RECORDED)
            ->exists());
    }

    public function test_starting_a_session_requires_consent_then_creates_an_active_consultation(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Session Patient');
        $this->createBranch($tenant);

        $this->actingAs($user);
        $this->bindTenant($tenant);

        $page = new SinaChat;

        // No consent yet: the session is never created (fails closed).
        $page->startSession();
        $this->bindTenant($tenant);
        $this->assertSame(0, Consultation::query()->count());

        $page->grantConsent();
        $page->startSession();

        $this->bindTenant($tenant);
        $session = Consultation::query()
            ->where('channel', Consultation::CHANNEL_SINA_CHAT)
            ->firstOrFail();

        $this->assertSame(Consultation::STATUS_IN_PROGRESS, $session->status);
        $this->assertSame($patient->getKey(), $session->patient_id);
    }

    // ============================ 4. Informed tariff / refund disclosure

    public function test_the_patient_consultations_page_discloses_the_tariff_and_refund_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        config()->set('lba.consultations.currency', 'IDR');
        config()->set('lba.consultations.tariff.in_person', 150000);
        config()->set('lba.consultations.cancellable_before_start', true);
        config()->set('lba.consultations.refundable', true);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        $patient = $this->registerPatient($tenant, $user, 'Synthetic Tariff Patient');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Tarif']);

        app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'scheduled_at' => now(),
        ], $owner);

        $this->actingAs($user)
            ->get('/patient/konsultasi-saya')
            ->assertOk()
            ->assertSee('Rp 150,000')
            ->assertSee(__('lba.patient.consultations.policy.cancellable'))
            ->assertSee(__('lba.patient.consultations.policy.refundable'));
    }

    public function test_no_tariff_is_fabricated_when_the_clinic_has_not_priced_the_channel(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        // No tariff configured for the channel: nothing must ever be invented.
        config()->set('lba.consultations.tariff.in_person', null);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        $patient = $this->registerPatient($tenant, $user, 'Synthetic Untariffed Patient');
        $branch = $this->createBranch($tenant);

        $consultation = app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'scheduled_at' => now(),
        ], $owner);

        $this->actingAs($user);
        $this->bindTenant($tenant);

        // The page shows no tariff when none is configured…
        $this->assertNull((new MyConsultations)->tariffLabel($consultation));

        // …and the rendered page carries no price row for it.
        $this->get('/patient/konsultasi-saya')
            ->assertOk()
            ->assertDontSee(__('lba.patient.consultations.tariff'), escape: false);
    }

    public function test_the_sina_chat_discloses_the_fee_unit_and_the_credit_balance(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        config()->set('lba.sina.billing.enabled', true);
        config()->set('lba.sina.billing.reserve_credits', 3);
        config()->set('lba.sina.billing.currency', 'sina_credit');

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $patient = $this->registerPatient($tenant, $user, 'Synthetic Fee Patient');

        app(SinaWalletService::class)->grant(
            (string) $tenant->getKey(),
            (string) $patient->getKey(),
            7,
            'synthetic test balance',
            'fee-grant-1',
        );

        $this->actingAs($user)
            ->get('/patient/sina')
            ->assertOk()
            ->assertSee(__('lba.patient.sina.fee_title'))
            ->assertSee(__('lba.patient.sina.fee_unit', ['credits' => 3, 'currency' => 'sina_credit']))
            ->assertSee(__('lba.patient.sina.fee_balance', ['balance' => 7]));
    }

    // ============================ 5. Patient self-booking and cancellation

    public function test_a_patient_books_a_consultation_from_the_portal(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Portal Booking');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Portal']);

        $this->actingAs($user);

        $html = $this->renderPage('/patient/konsultasi-saya');

        $this->postLivewireCalls($html, MyConsultations::class, [
            ['method' => 'submit'],
        ], [
            'branchId' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'scheduledAt' => now()->addDay()->format('Y-m-d H:i'),
            'reason' => 'Synthetic portal reason',
        ])->assertOk();

        $this->bindTenant($tenant);

        $consultation = Consultation::query()->firstOrFail();

        $this->assertSame($patient->getKey(), $consultation->patient_id);
        $this->assertSame((string) $branch->getKey(), (string) $consultation->branch_id);
        $this->assertSame(Consultation::CHANNEL_IN_PERSON, $consultation->channel);
        $this->assertSame(Consultation::STATUS_SCHEDULED, $consultation->status);
    }

    public function test_a_patient_cancels_a_not_started_consultation_from_the_portal(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Portal Cancel');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Portal Cancel']);

        $consultation = app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'status' => Consultation::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($user);

        $html = $this->renderPage('/patient/konsultasi-saya');

        $this->callLivewire($html, MyConsultations::class, 'cancel', [(string) $consultation->getKey()])
            ->assertOk();

        $this->bindTenant($tenant);
        $this->assertSame(
            Consultation::STATUS_CANCELLED,
            Consultation::query()->whereKey($consultation->getKey())->value('status'),
        );
    }

    public function test_a_started_consultation_is_never_cancelled_from_the_portal(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Portal Started');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Portal Started']);

        $consultation = app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'status' => Consultation::STATUS_IN_PROGRESS,
            'scheduled_at' => now(),
        ]);

        $this->actingAs($user);

        $html = $this->renderPage('/patient/konsultasi-saya');

        $this->callLivewire($html, MyConsultations::class, 'cancel', [(string) $consultation->getKey()])
            ->assertOk();

        $this->bindTenant($tenant);
        $this->assertSame(
            Consultation::STATUS_IN_PROGRESS,
            Consultation::query()->whereKey($consultation->getKey())->value('status'),
        );
    }
}
