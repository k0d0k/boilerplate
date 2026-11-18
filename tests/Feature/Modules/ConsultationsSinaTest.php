<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Consultations\Ai\Exceptions\SinaException;
use Modules\Consultations\Ai\Providers\OllamaChatProvider;
use Modules\Consultations\Ai\Providers\OpenAiCompatibleChatProvider;
use Modules\Consultations\Ai\SinaProviderResolver;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\AiProviderConfig;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationAuditLog;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Models\SinaWalletLedgerEntry;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\SinaChatService;
use Modules\Consultations\Services\SinaWalletService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Workstream 2 — Consultations + SINA (focused essentials).
 *
 * Covers the consultation lifecycle/tenancy, practitioner eligibility, the
 * tenant AI provider configuration, the patient SINA wallet accounting
 * (idempotent reserve/capture/release) and the SINA chat orchestration
 * (consent gate, billing, provider failure release). Every identity and
 * patient here is synthetic.
 */
class ConsultationsSinaTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'consultations';

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

    private function member(Tenant $tenant, string $roleKey, string $status = TenantMembership::STATUS_ACTIVE): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey], $status);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithOwner(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        return [$tenant, $owner];
    }

    private function makeConsultation(Tenant $tenant, User $owner, ?Patient &$patient = null): Consultation
    {
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic SINA Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => Consultation::CHANNEL_SINA_CHAT,
            'type' => Consultation::TYPE_CONSULTATION,
        ], $owner);
    }

    private function grantAiConsent(Patient $patient, User $owner, string $status = PatientConsent::STATUS_GRANTED): PatientConsent
    {
        return PatientConsent::query()->create([
            'patient_id' => $patient->getKey(),
            'consent_type' => PatientConsent::TYPE_AI_ASSISTANCE,
            'version' => '1',
            'status' => $status,
            'recorded_by' => $owner->getKey(),
            'recorded_at' => now(),
        ]);
    }

    private function configureSina(Tenant $tenant, string $baseUrl = 'https://sina.test/v1'): AiProviderConfig
    {
        return AiProviderConfig::query()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'label' => 'Synthetic SINA endpoint',
            'base_url' => $baseUrl,
            'model' => 'test-sina-model',
            'is_default' => true,
            'enabled' => true,
        ]);
    }

    private function grantSinaCredits(Tenant $tenant, Patient $patient, int $credits): void
    {
        app(SinaWalletService::class)->grant(
            (string) $tenant->getKey(),
            (string) $patient->getKey(),
            $credits,
            'synthetic test balance',
            'sina-grant:'.Str::ulid(),
        );
    }

    // ----------------------------------------------- Consultation core/tenancy

    public function test_a_consultation_is_created_as_scheduled_with_history_and_audit(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $consultation = $this->makeConsultation($tenant, $owner);

        $this->assertSame(Consultation::STATUS_SCHEDULED, $consultation->status);
        $this->assertSame($owner->getKey(), $consultation->created_by);
        $this->assertTrue(Str::isUlid((string) $consultation->getKey()));

        $this->assertDatabaseHas('consultations', ['id' => $consultation->getKey()], 'tenant');
        $this->assertDatabaseHas('consultation_status_histories', [
            'consultation_id' => $consultation->getKey(),
            'to_status' => Consultation::STATUS_SCHEDULED,
        ], 'tenant');
        $this->assertDatabaseHas('consultation_audit_logs', [
            'consultation_id' => $consultation->getKey(),
            'action' => ConsultationAuditLog::ACTION_CREATED,
        ], 'tenant');
    }

    public function test_the_consultation_lifecycle_only_moves_through_valid_transitions(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $consultation = $this->makeConsultation($tenant, $owner);
        $manager = app(ConsultationManager::class);

        $manager->transition($consultation, Consultation::STATUS_IN_PROGRESS, $owner);
        $manager->transition($consultation, Consultation::STATUS_COMPLETED, $owner);

        $this->assertSame(Consultation::STATUS_COMPLETED, $consultation->fresh()->status);

        try {
            $manager->transition($consultation->fresh(), Consultation::STATUS_IN_PROGRESS, $owner);
            $this->fail('A completed consultation must not transition further.');
        } catch (ConsultationException $exception) {
            $this->assertSame('invalid_transition', $exception->errorCode());
        }
    }

    public function test_a_foreign_patient_or_branch_is_rejected(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithOwner();
        $consultationA = $this->makeConsultation($tenantA, $ownerA);

        [$tenantB, $ownerB] = $this->tenantWithOwner();
        $branchB = $this->createBranch($tenantB);
        $patientB = app(PatientRegistrar::class)->create([
            'full_name' => 'Tenant B Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $ownerB);

        $this->bindTenant($tenantB);
        $manager = app(ConsultationManager::class);

        try {
            $manager->create([
                'patient_id' => $consultationA->patient_id, // tenant A id
                'branch_id' => $branchB->getKey(),
                'channel' => Consultation::CHANNEL_SINA_CHAT,
            ], $ownerB);
            $this->fail('A foreign patient must be rejected.');
        } catch (ConsultationException $exception) {
            $this->assertSame('unknown_patient', $exception->errorCode());
        }

        try {
            $manager->create([
                'patient_id' => $patientB->getKey(),
                'branch_id' => $consultationA->branch_id, // tenant A id
                'channel' => Consultation::CHANNEL_SINA_CHAT,
            ], $ownerB);
            $this->fail('A foreign branch must be rejected.');
        } catch (ConsultationException $exception) {
            $this->assertSame('unknown_branch', $exception->errorCode());
        }
    }

    public function test_a_practitioner_from_another_tenant_is_rejected(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $outsider = $this->member(Tenant::factory()->create(), RoleKey::RECEPTIONIST);

        $branch = $this->createBranch($tenant);
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Staff Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        try {
            app(ConsultationManager::class)->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $branch->getKey(),
                'staff_user_id' => $outsider->getKey(),
                'channel' => Consultation::CHANNEL_SINA_CHAT,
            ], $owner);
            $this->fail('An ineligible practitioner must be rejected.');
        } catch (ConsultationException $exception) {
            $this->assertSame('staff_not_eligible', $exception->errorCode());
        }
    }

    public function test_a_consultation_of_another_tenant_is_never_visible(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithOwner();
        $this->makeConsultation($tenantA, $ownerA);

        [$tenantB] = $this->tenantWithOwner();
        $this->bindTenant($tenantB);

        $this->assertSame(0, Consultation::query()->count());
    }

    // ----------------------------------------------- AI provider configuration

    public function test_the_provider_resolver_prefers_a_tenant_config_over_the_platform_default(): void
    {
        $tenant = Tenant::factory()->create();
        $resolver = app(SinaProviderResolver::class);

        // No config: the platform default (OpenAI-compatible) is used.
        $this->assertInstanceOf(OpenAiCompatibleChatProvider::class, $resolver->forTenant((string) $tenant->getKey()));

        // A tenant Ollama config takes precedence.
        AiProviderConfig::query()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OLLAMA,
            'base_url' => 'http://ollama.test:11434',
            'model' => 'llama-test',
            'is_default' => true,
            'enabled' => true,
        ]);

        $this->assertInstanceOf(OllamaChatProvider::class, $resolver->forTenant((string) $tenant->getKey()));
    }

    public function test_a_disabled_tenant_config_falls_back_to_the_platform_default(): void
    {
        $tenant = Tenant::factory()->create();

        AiProviderConfig::query()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OLLAMA,
            'base_url' => 'http://ollama.test:11434',
            'model' => 'llama-test',
            'enabled' => false,
        ]);

        $this->assertInstanceOf(
            OpenAiCompatibleChatProvider::class,
            app(SinaProviderResolver::class)->forTenant((string) $tenant->getKey()),
        );
    }

    public function test_an_empty_endpoint_is_never_used(): void
    {
        try {
            app(SinaProviderResolver::class)->make(AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE, '', '');
            $this->fail('An empty endpoint must never resolve to a provider.');
        } catch (SinaException $exception) {
            $this->assertSame('provider_not_configured', $exception->errorCode());
        }
    }

    public function test_the_provider_api_key_is_encrypted_at_rest_and_never_serialized(): void
    {
        $tenant = Tenant::factory()->create();

        $config = AiProviderConfig::query()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'base_url' => 'https://sina.test/v1',
            'model' => 'test-sina-model',
            'api_key' => 'super-secret-key',
            'enabled' => true,
        ]);

        $raw = (string) DB::connection((string) config('tenancy.central_connection'))
            ->table('ai_provider_configs')
            ->where('id', $config->getKey())
            ->value('api_key');

        $this->assertNotSame('super-secret-key', $raw);
        $this->assertSame('super-secret-key', $config->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $config->fresh()->toArray());
    }

    // ----------------------------------------------- SINA wallet accounting

    public function test_wallet_reserve_capture_and_release_are_accounted_and_idempotent(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Wallet Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $wallet = app(SinaWalletService::class);
        $tenantId = (string) $tenant->getKey();
        $patientId = (string) $patient->getKey();

        $wallet->grant($tenantId, $patientId, 100, 'test grant', 'g-1');

        $reserve = $wallet->reserve($tenantId, $patientId, 'cons-1', 30, 'r-1');
        $this->assertSame(70, $reserve->balance_after);
        $this->assertSame(30, $reserve->reserved_after);

        // A repeated reserve with the same key is a no-op (idempotent).
        $wallet->reserve($tenantId, $patientId, 'cons-1', 30, 'r-1');

        $wallet->capture($tenantId, $patientId, 'cons-1', 20, 'c-1', [
            'provider' => 'openai_compatible',
            'model_name' => 'test-sina-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ]);

        $release = $wallet->release($tenantId, $patientId, 'cons-1', 10, 'rel-1');

        $this->assertSame(80, $release->balance_after);
        $this->assertSame(0, $release->reserved_after);

        // Repeating the release never double-refunds.
        $wallet->release($tenantId, $patientId, 'cons-1', 10, 'rel-1');

        $this->assertSame(80, $wallet->balanceFor($tenantId, $patientId));
        $this->assertSame(1, SinaWalletLedgerEntry::query()->where('action', 'reserve')->count());
        $this->assertSame(1, SinaWalletLedgerEntry::query()->where('action', 'capture')->count());
        $this->assertSame(1, SinaWalletLedgerEntry::query()->where('action', 'release')->count());
        $this->assertSame(1, SinaUsageRecord::query()->where('tenant_id', $tenantId)->count());

        $stored = $wallet->walletFor($tenantId, $patientId);
        $this->assertSame(20, $stored->lifetime_consumed_credits);
        $this->assertSame(0, $stored->reserved_credits);
    }

    public function test_a_reservation_cannot_exceed_the_available_balance(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Broke Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $wallet = app(SinaWalletService::class);
        $wallet->grant((string) $tenant->getKey(), (string) $patient->getKey(), 10, 'test', 'g-2');

        try {
            $wallet->reserve((string) $tenant->getKey(), (string) $patient->getKey(), 'cons-x', 50, 'r-x');
            $this->fail('A reservation larger than the balance must be rejected.');
        } catch (SinaException $exception) {
            $this->assertSame('insufficient_credits', $exception->errorCode());
        }

        $this->assertSame(10, $wallet->balanceFor((string) $tenant->getKey(), (string) $patient->getKey()));
    }

    // ----------------------------------------------- SINA chat orchestration

    public function test_a_patient_turn_requires_explicit_ai_consent(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $patient = null;
        $consultation = $this->makeConsultation($tenant, $owner, $patient);
        $this->grantSinaCredits($tenant, $patient, 100);
        $this->configureSina($tenant);

        Http::fake(['sina.test/*' => Http::response([], 200)]);

        try {
            app(SinaChatService::class)->sendPatientMessage($consultation, $patient, 'Halo SINA.');
            $this->fail('A patient turn without AI consent must be rejected.');
        } catch (ConsultationException $exception) {
            $this->assertSame('consent_required', $exception->errorCode());
        }

        Http::assertNothingSent();
        $this->bindTenant($tenant);
        $this->assertSame(0, ConsultationMessage::query()->count());
    }

    public function test_a_sina_turn_reserves_captures_and_records_the_reply(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $patient = null;
        $consultation = $this->makeConsultation($tenant, $owner, $patient);
        $this->grantAiConsent($patient, $owner);
        $this->grantSinaCredits($tenant, $patient, 100);
        $this->configureSina($tenant);

        Http::fake([
            'sina.test/*' => Http::response([
                'model' => 'test-sina-model',
                'choices' => [['message' => ['content' => 'Halo, saya SINA.']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
            ], 200),
        ]);

        $reply = app(SinaChatService::class)->sendPatientMessage($consultation, $patient, 'Apa itu LBA?', $owner);

        $this->assertSame(ConsultationMessage::ROLE_ASSISTANT, $reply->role);
        $this->assertSame('Halo, saya SINA.', $reply->content);

        $this->bindTenant($tenant);
        $this->assertSame(2, ConsultationMessage::query()->count());
        $this->assertSame(
            1,
            ConsultationAuditLog::query()->where('action', ConsultationAuditLog::ACTION_SINA_TURN)->count(),
        );

        $wallet = app(SinaWalletService::class);
        $tenantId = (string) $tenant->getKey();
        $patientId = (string) $patient->getKey();

        // 20 tokens -> 1 credit (ceil at 1 credit / 1k tokens), fully captured.
        $this->assertSame(99, $wallet->balanceFor($tenantId, $patientId));
        $this->assertSame(1, $wallet->walletFor($tenantId, $patientId)->lifetime_consumed_credits);
        $this->assertSame(0, $wallet->walletFor($tenantId, $patientId)->reserved_credits);
        $this->assertSame(1, SinaUsageRecord::query()->where('tenant_id', $tenantId)->count());
    }

    public function test_a_provider_failure_releases_the_reservation(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $patient = null;
        $consultation = $this->makeConsultation($tenant, $owner, $patient);
        $this->grantAiConsent($patient, $owner);
        $this->grantSinaCredits($tenant, $patient, 100);
        $this->configureSina($tenant);

        Http::fake(['sina.test/*' => Http::response('upstream error', 500)]);

        try {
            app(SinaChatService::class)->sendPatientMessage($consultation, $patient, 'Halo SINA.', $owner);
            $this->fail('A failed provider call must surface an error.');
        } catch (SinaException $exception) {
            $this->assertSame('provider_unavailable', $exception->errorCode());
        }

        $wallet = app(SinaWalletService::class);
        $tenantId = (string) $tenant->getKey();
        $patientId = (string) $patient->getKey();

        // Reserved credits were fully returned; nothing was charged.
        $this->assertSame(100, $wallet->balanceFor($tenantId, $patientId));
        $this->assertSame(0, $wallet->walletFor($tenantId, $patientId)->reserved_credits);
        $this->assertSame(0, $wallet->walletFor($tenantId, $patientId)->lifetime_consumed_credits);
        $this->assertSame(0, SinaUsageRecord::query()->where('tenant_id', $tenantId)->count());
    }

    public function test_sina_is_unavailable_when_disabled(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $patient = null;
        $consultation = $this->makeConsultation($tenant, $owner, $patient);
        $this->grantAiConsent($patient, $owner);

        config()->set('lba.sina.enabled', false);

        try {
            app(SinaChatService::class)->sendPatientMessage($consultation, $patient, 'Halo SINA.');
            $this->fail('SINA must be unavailable when disabled.');
        } catch (SinaException $exception) {
            $this->assertSame('provider_unavailable', $exception->errorCode());
        }
    }
}
