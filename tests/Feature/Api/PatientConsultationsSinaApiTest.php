<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Api\PatientApiTokenIssuer;
use App\Platform\Branches\Models\TenantBranch;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Appointments\Models\Appointment;
use Modules\Consultations\Models\AiProviderConfig;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Models\ConsultationSinaTurn;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Models\SinaWallet;
use Modules\Consultations\Models\SinaWalletLedgerEntry;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\SinaWalletService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerRegistry;
use Modules\Practitioners\Services\PractitionerScheduleRegistry;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Workstream 2 — patient consultation history + SINA chat over the REST API.
 *
 * Exercises the admission boundary of the mobile API for the consultation
 * surface: bearer-token authentication scoped to ONE clinic, self-only
 * isolation (a foreign id resolves to nothing), the explicit AI-assistance
 * consent gate, the session lifecycle, per-turn credit accounting and the
 * provider adapter driven through a faked OpenAI-compatible HTTP endpoint.
 *
 * Only synthetic identities, patients and clinical objects are used
 * (DECISIONS.md PRV-01).
 */
class PatientConsultationsSinaApiTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const BASE = '/api/v1/patient';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();

        config([
            'lba.sina.enabled' => true,
            'lba.sina.billing.enabled' => true,
            'lba.sina.billing.reserve_credits' => 1,
            'lba.sina.billing.credits_per_1k_tokens' => 1,
        ]);
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

    private function bindPatient(Tenant $tenant, Patient $patient, User $account, User $actor): void
    {
        $this->bindTenant($tenant);

        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $actor);

        app(PatientAccountLinker::class)->redeemInvitation($account, $code);
    }

    private function issuedToken(User $user, Tenant $tenant, string $name = 'mobile'): string
    {
        return app(PatientApiTokenIssuer::class)->issue($user, (string) $tenant->getKey(), $name)['plain'];
    }

    private function configureTenantProvider(Tenant $tenant, string $baseUrl = 'https://sina.test/v1'): void
    {
        AiProviderConfig::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'label' => 'Synthetic provider',
            'base_url' => $baseUrl,
            'model' => 'gpt-test',
            'api_key' => 'top-secret',
            'is_default' => true,
            'enabled' => true,
        ]);
    }

    private function consultationFor(Patient $patient, string $branchId, string $status = Consultation::STATUS_SCHEDULED): Consultation
    {
        return app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branchId,
            'channel' => Consultation::CHANNEL_SINA_CHAT,
            'type' => Consultation::TYPE_CONSULTATION,
            'status' => $status,
            'scheduled_at' => now(),
        ]);
    }

    private function fakeProvider(string $content = 'Halo, ini SINA.', int $promptTokens = 10, int $completionTokens = 20): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-test',
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => $content]],
                ],
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                ],
            ], 200),
        ]);
    }

    /**
     * A verified, branch-authorized practitioner, the branch they are authorized
     * at, and the clinic owner who authorized them.
     *
     * @return array{0: Practitioner, 1: TenantBranch, 2: User}
     */
    private function rosterPractitioner(Tenant $tenant): array
    {
        $this->bindTenant($tenant);

        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Roster', 'timezone' => 'Asia/Jakarta']);
        $practitioner = $this->registerPractitioner($tenant, $owner, $branch);

        return [$practitioner, $branch, $owner];
    }

    private function registerPractitioner(
        Tenant $tenant,
        User $owner,
        TenantBranch $branch,
        bool $verify = true,
        bool $authorize = true,
    ): Practitioner {
        $this->bindTenant($tenant);

        $clinical = User::factory()->role(RoleKey::CONSULTANT)->create();
        $this->giveMembership($clinical, $tenant, [RoleKey::CONSULTANT]);

        $practitioner = app(PractitionerRegistry::class)->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Sintetis',
        ]);

        if ($verify) {
            app(PractitionerRegistry::class)->verify($owner, $practitioner);
        }

        if ($authorize) {
            app(PractitionerRegistry::class)->authorizeBranch($owner, $practitioner, (string) $branch->getKey());
        }

        return $practitioner->fresh();
    }

    /**
     * Open one recurring weekly window (Monday 09:00–12:00, branch wall-clock)
     * and return the first instant inside it, expressed in the branch timezone
     * so it is an absolute instant the schedule actually covers.
     */
    private function bookableWindow(User $owner, Practitioner $practitioner, TenantBranch $branch): Carbon
    {
        app(PractitionerScheduleRegistry::class)->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => Carbon::MONDAY,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $timezone = $branch->timezone ?: (string) config('app.timezone');

        return Carbon::now($timezone)->addWeek()->startOfWeek(Carbon::MONDAY)->setTime(9, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function practitionerBookingPayload(TenantBranch $branch, Practitioner $practitioner, Carbon $slot): array
    {
        return [
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'scheduled_at' => $slot->toIso8601String(),
            'practitioner_id' => (string) $practitioner->getKey(),
        ];
    }

    // ========================================= 1. Admission boundary

    public function test_protected_endpoints_require_a_valid_bearer_token(): void
    {
        $this->getJson(self::BASE.'/consultations')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->withToken('not-a-real-token')->getJson(self::BASE.'/sina')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    // ========================================= 2. Consultation history

    public function test_consultation_history_is_paginated_and_self_only(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Consult');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Consult');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Konsultasi']);

        $ownConsultation = $this->consultationFor($own, (string) $branch->getKey());
        $this->consultationFor($foreign, (string) $branch->getKey());

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)->getJson(self::BASE.'/consultations')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('meta.pagination.total'));
        $this->assertSame((string) $ownConsultation->getKey(), (string) $response->json('data.0.id'));

        // A status filter that is not a real status is rejected.
        $this->withToken($token)->getJson(self::BASE.'/consultations?status=not-a-status')
            ->assertStatus(422);
    }

    public function test_a_foreign_consultation_cannot_be_shown_or_messaged(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Own Show');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Show');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Show']);

        $foreignConsultation = $this->consultationFor($foreign, (string) $branch->getKey());

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->getJson(self::BASE.'/consultations/'.$foreignConsultation->getKey())
            ->assertStatus(404);

        $this->withToken($token)->getJson(self::BASE.'/consultations/'.$foreignConsultation->getKey().'/messages')
            ->assertStatus(404);
    }

    // ========================================= 2b. Human booking and cancellation

    public function test_a_patient_can_book_a_human_consultation_at_an_active_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Booking');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Booking']);

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)->postJson(self::BASE.'/consultations', [
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'reason' => 'Synthetic reason',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', Consultation::STATUS_SCHEDULED)
            ->assertJsonPath('data.channel', Consultation::CHANNEL_IN_PERSON)
            ->assertJsonPath('data.branch.id', (string) $branch->getKey())
            ->assertJsonPath('data.reason', 'Synthetic reason')
            ->assertJsonPath('data.can_chat', false);

        // The booking always belongs to the acting patient's OWN record.
        $this->bindTenant($tenant);
        $created = Consultation::query()->whereKey((string) $response->json('data.id'))->first();

        $this->assertNotNull($created);
        $this->assertSame($patient->getKey(), $created->patient_id);
    }

    public function test_a_consultation_cannot_be_booked_at_an_unavailable_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Bad Branch');
        $inactive = $this->createBranch($tenant, [
            'name' => 'Cabang Nonaktif',
            'status' => TenantBranch::STATUS_INACTIVE,
        ]);

        $token = $this->issuedToken($user, $tenant);

        // An inactive branch of this clinic.
        $this->withToken($token)->postJson(self::BASE.'/consultations', [
            'branch_id' => (string) $inactive->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['branch_id']]]);

        // A branch id that does not exist in this tenant at all.
        $this->withToken($token)->postJson(self::BASE.'/consultations', [
            'branch_id' => 'branch-does-not-exist',
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['branch_id']]]);
    }

    public function test_human_booking_rejects_the_sina_channel_and_a_past_schedule(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Channel Guard');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Channel']);

        $token = $this->issuedToken($user, $tenant);

        // SINA chat is created through the SINA endpoints, never here.
        $this->withToken($token)->postJson(self::BASE.'/consultations', [
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_SINA_CHAT,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['channel']]]);

        // A consultation is always booked for a future time.
        $this->withToken($token)->postJson(self::BASE.'/consultations', [
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'scheduled_at' => now()->subDay()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['scheduled_at']]]);
    }

    public function test_a_patient_can_cancel_their_own_scheduled_consultation(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Cancel Own');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Cancel']);

        $consultation = $this->consultationFor($patient, (string) $branch->getKey());

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/consultations/'.$consultation->getKey().'/cancel', [
            'note' => 'Synthetic cancellation',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Consultation::STATUS_CANCELLED);

        $this->bindTenant($tenant);
        $this->assertSame(
            Consultation::STATUS_CANCELLED,
            Consultation::query()->whereKey($consultation->getKey())->value('status'),
        );
    }

    public function test_a_foreign_or_started_consultation_cannot_be_cancelled(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Cancel Owner');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Cancel Foreign');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Cancel Guard']);

        $token = $this->issuedToken($user, $tenant);

        // Someone else's consultation and a started one of the acting patient,
        // both created while the verified tenant database is bound.
        $foreignConsultation = $this->consultationFor($foreign, (string) $branch->getKey());
        $started = $this->consultationFor($patient, (string) $branch->getKey(), Consultation::STATUS_IN_PROGRESS);

        // Someone else's consultation simply does not resolve (404).
        $this->withToken($token)->postJson(self::BASE.'/consultations/'.$foreignConsultation->getKey().'/cancel')
            ->assertStatus(404);

        // A session that already started is no longer cancellable by the patient.
        $this->withToken($token)->postJson(self::BASE.'/consultations/'.$started->getKey().'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'not_cancellable');
    }

    public function test_cancellation_is_refused_when_the_clinic_policy_disallows_it(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        config()->set('lba.consultations.cancellable_before_start', false);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Cancel Policy');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Cancel Policy']);

        $consultation = $this->consultationFor($patient, (string) $branch->getKey());

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/consultations/'.$consultation->getKey().'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'not_cancellable');
    }

    // ========================================= 3. Consent gate

    public function test_recording_consent_writes_the_consent_and_the_audit_trail(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Consent');

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/sina/consent')
            ->assertStatus(201)
            ->assertJsonPath('data.consent', true);

        $this->bindTenant($tenant);

        $this->assertDatabaseHas('patient_consents', [
            'patient_id' => $patient->getKey(),
            'consent_type' => PatientConsent::TYPE_AI_ASSISTANCE,
            'status' => PatientConsent::STATUS_GRANTED,
        ], 'tenant');

        $this->assertDatabaseHas('patient_audit_logs', [
            'patient_id' => $patient->getKey(),
            'action' => PatientAuditLog::ACTION_CONSENT_RECORDED,
        ], 'tenant');
    }

    // ========================================= 4. Session lifecycle

    public function test_a_sina_session_requires_consent_then_creates_an_in_progress_session(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Session');
        $this->createBranch($tenant, ['name' => 'Cabang Sesi']);

        $token = $this->issuedToken($user, $tenant);

        // No consent yet → fail closed.
        $this->withToken($token)->postJson(self::BASE.'/sina/session')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'consent_required');

        $this->withToken($token)->postJson(self::BASE.'/sina/consent')->assertStatus(201);

        $created = $this->withToken($token)->postJson(self::BASE.'/sina/session')
            ->assertStatus(201)
            ->assertJsonPath('data.status', Consultation::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.channel', Consultation::CHANNEL_SINA_CHAT);

        $sessionId = (string) $created->json('data.id');

        // A second start returns the SAME running session (idempotent).
        $this->withToken($token)->postJson(self::BASE.'/sina/session')
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId);
    }

    public function test_sending_a_message_runs_a_turn_and_debits_one_credit(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Turn');
        $this->createBranch($tenant, ['name' => 'Cabang Turn']);

        $this->configureTenantProvider($tenant);
        $this->fakeProvider('Ringkasan: istirahat cukup.', 10, 20);

        $tenantId = (string) $tenant->getKey();
        app(SinaWalletService::class)
            ->grant($tenantId, (string) $patient->getKey(), 5, 'test grant', 'grant-1');

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/sina/consent')->assertStatus(201);
        $this->withToken($token)->postJson(self::BASE.'/sina/session')->assertStatus(201);

        $this->withToken($token)->postJson(self::BASE.'/sina/messages', [
            'content' => 'Saya merasa lelah.',
        ])
            ->assertOk()
            ->assertJsonPath('data.reply.role', ConsultationMessage::ROLE_ASSISTANT)
            ->assertJsonPath('data.reply.content', 'Ringkasan: istirahat cukup.');

        // 30 total tokens → 1 credit captured; the reservation is fully cleared.
        $this->withToken($token)->getJson(self::BASE.'/sina/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 4)
            ->assertJsonPath('data.wallet.reserved_credits', 0)
            ->assertJsonPath('data.wallet.lifetime_consumed_credits', 1)
            ->assertJsonPath('data.usage_total_credits', 1)
            ->assertJsonPath('data.usage_turns', 1);

        $this->bindTenant($tenant);
        $this->assertSame(1, SinaUsageRecord::query()->count());

        // A blank message is rejected by validation.
        $this->withToken($token)->postJson(self::BASE.'/sina/messages', ['content' => ''])
            ->assertStatus(422);
    }

    public function test_a_retried_turn_with_the_same_reference_is_never_double_charged(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Retry');
        $this->createBranch($tenant, ['name' => 'Cabang Retry']);

        $this->configureTenantProvider($tenant);
        $this->fakeProvider('Balasan idempoten.', 10, 20);

        $tenantId = (string) $tenant->getKey();
        app(SinaWalletService::class)
            ->grant($tenantId, (string) $patient->getKey(), 5, 'test grant', 'grant-retry');

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/sina/consent')->assertStatus(201);
        $this->bindTenant($tenant);
        $sessionId = (string) $this->withToken($token)->postJson(self::BASE.'/sina/session')->assertStatus(201)->json('data.id');

        $payload = ['content' => 'Apakah saya perlu istirahat?', 'turn_reference' => 'client-turn-1'];

        // A client that retries after a dropped response sends the SAME
        // reference — the second request must return the STORED reply, without
        // adding a message, calling the provider again or moving a credit.
        $first = $this->withToken($token)->postJson(self::BASE.'/sina/messages', $payload)->assertOk();
        $second = $this->withToken($token)->postJson(self::BASE.'/sina/messages', $payload)->assertOk();

        $this->assertNotNull($first->json('data.reply.id'));
        $this->assertSame(
            (string) $first->json('data.reply.id'),
            (string) $second->json('data.reply.id'),
        );

        $this->withToken($token)->getJson(self::BASE.'/sina/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 4)
            ->assertJsonPath('data.wallet.reserved_credits', 0)
            ->assertJsonPath('data.wallet.lifetime_consumed_credits', 1)
            ->assertJsonPath('data.usage_turns', 1)
            ->assertJsonPath('data.usage_total_credits', 1);

        // Exactly ONE provider call across both requests.
        Http::assertSentCount(1);

        $this->bindTenant($tenant);

        // One settled usage record, one reserve and one capture — never double.
        $this->assertSame(1, SinaUsageRecord::query()->count());
        $this->assertSame(1, SinaWalletLedgerEntry::query()
            ->where('action', SinaWalletLedgerEntry::ACTION_RESERVE)->count());
        $this->assertSame(1, SinaWalletLedgerEntry::query()
            ->where('action', SinaWalletLedgerEntry::ACTION_CAPTURE)->count());

        // The replay added NO transcript rows: one patient + one assistant.
        $this->assertSame(2, ConsultationMessage::query()
            ->where('consultation_id', $sessionId)->count());

        $turn = ConsultationSinaTurn::query()->where('consultation_id', $sessionId)->first();
        $this->assertNotNull($turn);
        $this->assertSame(ConsultationSinaTurn::STATUS_SUCCEEDED, $turn->status);
        $this->assertSame(1, $turn->attempts);
        $this->assertSame(1, $turn->provider_calls);
    }

    public function test_a_turn_reference_reused_with_different_content_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Conflict');
        $this->createBranch($tenant, ['name' => 'Cabang Conflict']);

        $this->configureTenantProvider($tenant);
        $this->fakeProvider('Balasan konflik.', 10, 20);

        $tenantId = (string) $tenant->getKey();
        app(SinaWalletService::class)
            ->grant($tenantId, (string) $patient->getKey(), 5, 'test grant', 'grant-conflict');

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/sina/consent')->assertStatus(201);
        $this->withToken($token)->postJson(self::BASE.'/sina/session')->assertStatus(201);

        $this->withToken($token)->postJson(self::BASE.'/sina/messages', [
            'content' => 'Pesan pertama.',
            'turn_reference' => 'client-turn-dup',
        ])->assertOk();

        // The SAME reference with DIFFERENT content is a client bug, not a retry.
        $this->withToken($token)->postJson(self::BASE.'/sina/messages', [
            'content' => 'Pesan yang berbeda.',
            'turn_reference' => 'client-turn-dup',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'turn_reference_conflict');

        Http::assertSentCount(1);

        $this->withToken($token)->getJson(self::BASE.'/sina/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 4)
            ->assertJsonPath('data.wallet.lifetime_consumed_credits', 1);

        $this->bindTenant($tenant);
        $this->assertSame(2, ConsultationMessage::query()->count());
        $this->assertSame(1, SinaUsageRecord::query()->count());
    }

    public function test_a_duplicate_of_an_in_flight_turn_is_refused_without_a_charge(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic In Flight');
        $this->createBranch($tenant, ['name' => 'Cabang In Flight']);

        $this->configureTenantProvider($tenant);
        $this->fakeProvider('Balasan paralel.', 10, 20);

        $tenantId = (string) $tenant->getKey();
        app(SinaWalletService::class)
            ->grant($tenantId, (string) $patient->getKey(), 5, 'test grant', 'grant-flight');

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/sina/consent')->assertStatus(201);

        $this->bindTenant($tenant);
        $sessionId = (string) $this->withToken($token)->postJson(self::BASE.'/sina/session')->assertStatus(201)->json('data.id');

        // Simulate a parallel duplicate whose first request is still running.
        $this->bindTenant($tenant);
        ConsultationSinaTurn::query()->create([
            'consultation_id' => $sessionId,
            'turn_reference' => 'client-turn-flight',
            'content_hash' => hash('sha256', 'Sedang diproses.'),
            'status' => ConsultationSinaTurn::STATUS_PENDING,
            'attempts' => 1,
        ]);

        $this->withToken($token)->postJson(self::BASE.'/sina/messages', [
            'content' => 'Sedang diproses.',
            'turn_reference' => 'client-turn-flight',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'turn_in_progress');

        // Nothing was sent to the provider and nothing was charged.
        Http::assertNothingSent();

        $this->withToken($token)->getJson(self::BASE.'/sina/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 5)
            ->assertJsonPath('data.wallet.reserved_credits', 0)
            ->assertJsonPath('data.wallet.lifetime_consumed_credits', 0);
    }

    public function test_a_failed_turn_releases_its_reservation_and_a_retry_is_billed_once(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Fail Retry');
        $this->createBranch($tenant, ['name' => 'Cabang Fail Retry']);

        $this->configureTenantProvider($tenant);

        // The first attempt fails at the provider; the retry succeeds.
        Http::fakeSequence('*/chat/completions')
            ->push(['error' => 'boom'], 500)
            ->push([
                'model' => 'gpt-test',
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Pulih.']],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
            ], 200);

        $tenantId = (string) $tenant->getKey();
        app(SinaWalletService::class)
            ->grant($tenantId, (string) $patient->getKey(), 5, 'test grant', 'grant-fail');

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/sina/consent')->assertStatus(201);

        $this->bindTenant($tenant);
        $sessionId = (string) $this->withToken($token)->postJson(self::BASE.'/sina/session')->assertStatus(201)->json('data.id');

        $payload = ['content' => 'Butuh jawaban.', 'turn_reference' => 'client-turn-fail'];

        // First attempt: the provider is unavailable → the reservation is released.
        $this->withToken($token)->postJson(self::BASE.'/sina/messages', $payload)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'provider_unavailable');

        $this->withToken($token)->getJson(self::BASE.'/sina/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 5)
            ->assertJsonPath('data.wallet.reserved_credits', 0)
            ->assertJsonPath('data.wallet.lifetime_consumed_credits', 0)
            ->assertJsonPath('data.usage_turns', 0);

        // Retry with the SAME reference → processed and billed exactly once.
        $this->withToken($token)->postJson(self::BASE.'/sina/messages', $payload)
            ->assertOk()
            ->assertJsonPath('data.reply.content', 'Pulih.');

        $this->withToken($token)->getJson(self::BASE.'/sina/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 4)
            ->assertJsonPath('data.wallet.reserved_credits', 0)
            ->assertJsonPath('data.wallet.lifetime_consumed_credits', 1)
            ->assertJsonPath('data.usage_turns', 1)
            ->assertJsonPath('data.usage_total_credits', 1);

        Http::assertSentCount(2);

        $this->bindTenant($tenant);

        $this->assertSame(1, SinaUsageRecord::query()->count());
        $this->assertSame(1, SinaWalletLedgerEntry::query()
            ->where('action', SinaWalletLedgerEntry::ACTION_CAPTURE)->count());

        // One patient + one assistant — the retry reused the patient message.
        $this->assertSame(2, ConsultationMessage::query()
            ->where('consultation_id', $sessionId)->count());

        $turn = ConsultationSinaTurn::query()->where('consultation_id', $sessionId)->first();
        $this->assertNotNull($turn);
        $this->assertSame(ConsultationSinaTurn::STATUS_SUCCEEDED, $turn->status);
        $this->assertSame(2, $turn->attempts);
        $this->assertSame(2, $turn->provider_calls);
    }

    public function test_a_turn_reference_is_isolated_to_its_own_patient_session(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->configureTenantProvider($tenant);
        $this->fakeProvider('Balasan per pasien.', 10, 20);

        $this->createBranch($tenant, ['name' => 'Cabang Isolasi']);
        $tenantId = (string) $tenant->getKey();
        $wallets = app(SinaWalletService::class);

        $ids = [];

        // Two patients each run their OWN session with the SAME reference string.
        foreach (['Synthetic Iso A', 'Synthetic Iso B'] as $name) {
            // The request-scoped bearer guard caches the resolved identity for the
            // duration of one request; production serves each call as a fresh
            // request, so drop the guard to mimic the next inbound call and prove
            // tenant/patient isolation across separate patients.
            $this->app['auth']->forgetGuards();

            $user = $this->patientUser($tenant);
            $this->bindTenant($tenant);
            $patient = $this->registerPatient($tenant, $user, $name);
            $wallets->grant($tenantId, (string) $patient->getKey(), 5, 'iso', 'iso-'.$name);

            $token = $this->issuedToken($user, $tenant);
            $this->withToken($token)->postJson(self::BASE.'/sina/consent')->assertStatus(201);

            $this->bindTenant($tenant);
            $sessionId = (string) $this->withToken($token)->postJson(self::BASE.'/sina/session')->assertStatus(201)->json('data.id');

            $this->withToken($token)->postJson(self::BASE.'/sina/messages', [
                'content' => 'Halo SINA.',
                'turn_reference' => 'shared-reference',
            ])
                ->assertOk()
                ->assertJsonPath('data.reply.content', 'Balasan per pasien.');

            // Each patient is billed exactly once for their own turn.
            $this->withToken($token)->getJson(self::BASE.'/sina/wallet')
                ->assertOk()
                ->assertJsonPath('data.wallet.balance_credits', 4)
                ->assertJsonPath('data.wallet.lifetime_consumed_credits', 1)
                ->assertJsonPath('data.usage_turns', 1);

            $ids[] = $sessionId;
        }

        $this->assertNotSame($ids[0], $ids[1]);

        $this->bindTenant($tenant);
        $this->assertSame(2, ConsultationSinaTurn::query()
            ->where('turn_reference', 'shared-reference')->count());
    }

    public function test_the_wallet_and_usage_endpoints_report_only_the_own_patients_records(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $own = $this->registerPatient($tenant, $user, 'Synthetic Wallet Own');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Wallet Foreign');

        $tenantId = (string) $tenant->getKey();
        $wallets = app(SinaWalletService::class);

        // The own patient owns 5 credits; a foreign patient has their own wallet.
        $wallets->grant($tenantId, (string) $own->getKey(), 5, 'own', 'own-1');
        $wallets->grant($tenantId, (string) $foreign->getKey(), 9, 'foreign', 'foreign-1');

        $branch = $this->createBranch($tenant, ['name' => 'Cabang Wallet']);
        $consultation = $this->consultationFor($own, (string) $branch->getKey(), Consultation::STATUS_IN_PROGRESS);

        // A usage record for the own patient.
        $wallets->reserve($tenantId, (string) $own->getKey(), (string) $consultation->getKey(), 1, 'res-1');
        $wallets->capture($tenantId, (string) $own->getKey(), (string) $consultation->getKey(), 1, 'cap-1', [
            'provider' => 'openai_compatible',
            'model_name' => 'gpt-test',
        ]);

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->getJson(self::BASE.'/sina/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 4)
            ->assertJsonPath('data.usage_turns', 1);

        $usage = $this->withToken($token)->getJson(self::BASE.'/sina/usage')->assertOk();

        $this->assertCount(1, $usage->json('data'));
        $this->assertSame(1, $usage->json('meta.pagination.total'));

        $this->withToken($token)->getJson(self::BASE.'/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 4)
            ->assertJsonPath('data.usage_total_credits', 1)
            ->assertJsonPath('data.usage_turns', 1);

        $this->withToken($token)->getJson(self::BASE.'/wallet/usage')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.credit_units', 1);

        $this->bindTenant($tenant);
        $this->assertSame(1, SinaWallet::query()->where('patient_id', $own->getKey())->count());
        $this->assertSame(1, SinaUsageRecord::query()->where('patient_id', $own->getKey())->count());
    }

    // ========================================= 5. Informed tariff / fee disclosure

    public function test_the_consultation_payload_discloses_the_informed_tariff_and_refund_terms(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        config()->set('lba.consultations.currency', 'IDR');
        config()->set('lba.consultations.tariff.in_person', 200000);
        config()->set('lba.consultations.cancellable_before_start', true);
        config()->set('lba.consultations.refundable', true);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Tariff API');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Tarif API']);

        app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'scheduled_at' => now(),
        ]);

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->getJson(self::BASE.'/consultations')
            ->assertOk()
            ->assertJsonPath('data.0.tariff.amount', '200000')
            ->assertJsonPath('data.0.tariff.currency', 'IDR')
            ->assertJsonPath('data.0.refund.cancellable_before_start', true)
            ->assertJsonPath('data.0.refund.refundable', true);
    }

    public function test_no_tariff_is_fabricated_when_the_clinic_has_not_priced_the_channel(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        config()->set('lba.consultations.tariff.in_person', null);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Untariffed API');
        $branch = $this->createBranch($tenant);

        app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'scheduled_at' => now(),
        ]);

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->getJson(self::BASE.'/consultations')
            ->assertOk()
            ->assertJsonPath('data.0.tariff', null);
    }

    public function test_the_sina_session_payload_discloses_the_fee_unit(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        config()->set('lba.sina.billing.enabled', true);
        config()->set('lba.sina.billing.reserve_credits', 2);
        config()->set('lba.sina.billing.credits_per_1k_tokens', 1);
        config()->set('lba.sina.billing.currency', 'sina_credit');

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Fee API');

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->getJson(self::BASE.'/sina')
            ->assertOk()
            ->assertJsonPath('data.fee.billing_enabled', true)
            ->assertJsonPath('data.fee.credits_per_turn', 2)
            ->assertJsonPath('data.fee.credits_per_1k_tokens', 1)
            ->assertJsonPath('data.fee.currency', 'sina_credit');
    }

    // ========================================= 6. Practitioner availability wiring

    public function test_a_patient_can_book_a_consultation_with_an_available_practitioner(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Prac Booking');

        [$practitioner, $branch, $owner] = $this->rosterPractitioner($tenant);
        $slot = $this->bookableWindow($owner, $practitioner, $branch);

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)
            ->postJson(self::BASE.'/consultations', $this->practitionerBookingPayload($branch, $practitioner, $slot));
        $response
            ->assertStatus(201)
            ->assertJsonPath('data.status', Consultation::STATUS_SCHEDULED)
            ->assertJsonPath('data.practitioner.id', (string) $practitioner->getKey())
            ->assertJsonPath('data.practitioner.name', 'Dr Sintetis');

        $appointmentId = $response->json('data.appointment.id');
        $this->assertNotNull($appointmentId);
        $this->assertNotNull($response->json('data.appointment.starts_at'));

        $this->bindTenant($tenant);

        $consultation = Consultation::query()->whereKey((string) $response->json('data.id'))->first();

        $this->assertNotNull($consultation);
        $this->assertSame($patient->getKey(), $consultation->patient_id);
        $this->assertSame((string) $practitioner->user_id, (string) $consultation->staff_user_id);
        $this->assertSame((string) $appointmentId, (string) $consultation->appointment_id);

        $this->assertDatabaseHas('appointments', [
            'id' => (string) $appointmentId,
            'branch_id' => (string) $branch->getKey(),
            'staff_user_id' => (string) $practitioner->user_id,
            'status' => Appointment::STATUS_SCHEDULED,
        ], 'tenant');
    }

    public function test_a_slot_already_taken_by_the_practitioner_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Slot Clash');

        [$practitioner, $branch, $owner] = $this->rosterPractitioner($tenant);
        $slot = $this->bookableWindow($owner, $practitioner, $branch);

        $payload = $this->practitionerBookingPayload($branch, $practitioner, $slot);
        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/consultations', $payload)->assertStatus(201);

        // The exact same practitioner slot can never be confirmed twice.
        $this->withToken($token)->postJson(self::BASE.'/consultations', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'slot_unavailable');

        $this->bindTenant($tenant);
        $this->assertSame(1, Appointment::query()->where('staff_user_id', (string) $practitioner->user_id)->count());
    }

    public function test_booking_with_an_unverified_or_unauthorized_practitioner_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Prac Guard');

        [$practitioner, $branch, $owner] = $this->rosterPractitioner($tenant);
        $slot = $this->bookableWindow($owner, $practitioner, $branch);

        $token = $this->issuedToken($user, $tenant);

        // A registered-but-not-yet-VERIFIED practitioner is never bookable.
        $pending = $this->registerPractitioner($tenant, $owner, $branch, verify: false);

        $this->withToken($token)->postJson(self::BASE.'/consultations', [
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'scheduled_at' => $slot->toIso8601String(),
            'practitioner_id' => (string) $pending->getKey(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'practitioner_unavailable');

        // A verified practitioner who is NOT authorized for this branch is refused
        // just the same: branch authorization is re-derived, never assumed.
        app(PractitionerRegistry::class)->revokeBranch($owner, $practitioner, (string) $branch->getKey());

        $this->withToken($token)->postJson(self::BASE.'/consultations', $this->practitionerBookingPayload($branch, $practitioner, $slot))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'practitioner_unavailable');

        $this->bindTenant($tenant);
        $this->assertSame(0, Consultation::query()->count());
    }

    public function test_booking_with_an_unknown_or_foreign_practitioner_id_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Prac Foreign');

        [$practitioner, $branch, $owner] = $this->rosterPractitioner($tenant);
        $slot = $this->bookableWindow($owner, $practitioner, $branch);

        $token = $this->issuedToken($user, $tenant);

        // An id that exists nowhere in this tenant.
        $this->withToken($token)->postJson(self::BASE.'/consultations', [
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'scheduled_at' => $slot->toIso8601String(),
            'practitioner_id' => 'practitioner-does-not-exist',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'unknown_practitioner');

        // A VERIFIED practitioner of ANOTHER clinic can never be booked, even when
        // the raw id is known: the lookup is scoped to the token's clinic.
        $other = Tenant::factory()->create();
        $this->provisionTenantDatabase($other);
        [$foreign] = $this->rosterPractitioner($other);

        $this->bindTenant($tenant);

        $this->withToken($token)->postJson(self::BASE.'/consultations', [
            'branch_id' => (string) $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'scheduled_at' => $slot->toIso8601String(),
            'practitioner_id' => (string) $foreign->getKey(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'unknown_practitioner');
    }

    public function test_cancelling_a_practitioner_consultation_releases_the_slot(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Prac Cancel');

        [$practitioner, $branch, $owner] = $this->rosterPractitioner($tenant);
        $slot = $this->bookableWindow($owner, $practitioner, $branch);

        $payload = $this->practitionerBookingPayload($branch, $practitioner, $slot);
        $token = $this->issuedToken($user, $tenant);

        $created = $this->withToken($token)->postJson(self::BASE.'/consultations', $payload)->assertStatus(201);
        $consultationId = (string) $created->json('data.id');
        $appointmentId = (string) $created->json('data.appointment.id');

        $this->withToken($token)->postJson(self::BASE.'/consultations/'.$consultationId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', Consultation::STATUS_CANCELLED);

        // Cancelling the consultation cancels the linked appointment, freeing the slot.
        $this->bindTenant($tenant);
        $this->assertSame(
            Appointment::STATUS_CANCELLED,
            Appointment::query()->whereKey($appointmentId)->value('status'),
        );

        // The very same slot (and practitioner) can be booked again.
        $this->withToken($token)->postJson(self::BASE.'/consultations', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.practitioner.id', (string) $practitioner->getKey());
    }

    public function test_cancellation_discloses_the_refund_policy_without_moving_a_credit_ledger(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        config()->set('lba.consultations.cancellable_before_start', true);
        config()->set('lba.consultations.refundable', true);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Prac Refund');

        [$practitioner, $branch, $owner] = $this->rosterPractitioner($tenant);
        $slot = $this->bookableWindow($owner, $practitioner, $branch);

        $token = $this->issuedToken($user, $tenant);

        $created = $this->withToken($token)
            ->postJson(self::BASE.'/consultations', $this->practitionerBookingPayload($branch, $practitioner, $slot))
            ->assertStatus(201)
            ->assertJsonPath('data.refund.refundable', true)
            ->assertJsonPath('data.refund.cancellable_before_start', true);

        $this->withToken($token)->postJson(self::BASE.'/consultations/'.(string) $created->json('data.id').'/cancel')
            ->assertOk()
            // The disclosed refund terms are POLICY, exactly as configured.
            ->assertJsonPath('data.refund.refundable', true);

        // ...and never a credit movement: cancelling a human session writes NO
        // token-wallet ledger entry and no SINA usage record. A refund POLICY is
        // disclosed here; the actual credit ledger is untouched.
        $this->bindTenant($tenant);

        $this->assertSame(0, SinaWallet::query()->where('patient_id', $patient->getKey())->count());
        $this->assertSame(0, SinaWalletLedgerEntry::query()->count());
        $this->assertSame(0, SinaUsageRecord::query()->count());
    }

    public function test_a_practitioner_consultation_is_isolated_to_its_own_patient(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $ownerUser = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $ownerUser, 'Synthetic Iso Owner');

        [$practitioner, $branch, $owner] = $this->rosterPractitioner($tenant);
        $slot = $this->bookableWindow($owner, $practitioner, $branch);

        $ownerToken = $this->issuedToken($ownerUser, $tenant);
        $booked = $this->withToken($ownerToken)
            ->postJson(self::BASE.'/consultations', $this->practitionerBookingPayload($branch, $practitioner, $slot))
            ->assertStatus(201);
        $consultationId = (string) $booked->json('data.id');

        // A DIFFERENT patient of the SAME clinic gets their own identity: the
        // request-scoped bearer guard caches the resolved user, so drop it to
        // mimic the next inbound call.
        $this->app['auth']->forgetGuards();

        $otherUser = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $otherUser, 'Synthetic Iso Other');
        $otherToken = $this->issuedToken($otherUser, $tenant);

        $this->withToken($otherToken)->getJson(self::BASE.'/consultations')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        $this->withToken($otherToken)->getJson(self::BASE.'/consultations/'.$consultationId)->assertStatus(404);
        $this->withToken($otherToken)->postJson(self::BASE.'/consultations/'.$consultationId.'/cancel')->assertStatus(404);
    }
}
