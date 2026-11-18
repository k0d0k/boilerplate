<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Api\PatientApiTokenIssuer;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Services\SinaWalletService;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 8B — patient mobile API CONTRACT / SMOKE journey.
 *
 * One coherent walk-through of the GoodBarber-facing proof-of-concept contract:
 * the same four calls a mobile shell has to make to be useful —
 *   login → choose the clinic → book an appointment → download a released report.
 *
 * The granular assertions live in {@see PatientApiTest}; this test pins the
 * SHAPE of the journey (endpoint order, envelope, status codes) so an external
 * app can be built against it, and re-proves that the tenant / branch /
 * inter-patient boundaries hold at every step of that path.
 */
class PatientJourneyContractTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'lba';

    private const BASE = '/api/v1/patient';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Storage::fake('lba');
        Storage::fake('lba-reports');
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

    private function activate(Tenant $tenant): void
    {
        if (! RegisteredModule::query()->where('module_key', self::MODULE)->exists()) {
            RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();
        }

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

    /**
     * Drive one examination all the way to an APPROVED report version that is
     * NOT yet released to the patient (the operator never approves their own
     * work, so a distinct reviewer signs it off).
     */
    private function approvedReportFor(Tenant $tenant, Patient $patient, string $branchName): ReportVersion
    {
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);

        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant, ['name' => $branchName]);

        $examination = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $operator);

        $assessment = app(AssessmentRecorder::class)->openFor($examination, $operator);

        $brand = Brand::query()->create([
            'name' => 'Brand '.Str::upper(Str::random(4)),
            'slug' => 'brand-'.Str::lower(Str::random(6)),
            'status' => Brand::STATUS_ACTIVE,
        ]);
        $brand->tenants()->attach($tenant->getKey(), ['is_primary' => true]);

        app(AssessmentRecorder::class)->selectBrand($assessment, (string) $tenant->getKey(), (string) $brand->getKey(), $operator);
        app(AssessmentRecorder::class)->submitForReview($assessment->fresh(), $operator);

        $version = app(AssessmentReviewer::class)->approve($assessment->fresh(), (string) $tenant->getKey(), $reviewer);

        $this->bindTenant($tenant);

        return $version;
    }

    /**
     * Approve, then release to the patient through a SECOND, different reviewer.
     */
    private function releasedReportFor(Tenant $tenant, Patient $patient, string $branchName): ReportVersion
    {
        $version = $this->approvedReportFor($tenant, $patient, $branchName);

        $releaser = $this->member($tenant, RoleKey::REVIEWER);

        return app(AssessmentReviewer::class)->releaseToPatient($version, (string) $tenant->getKey(), $releaser);
    }

    /**
     * Fund a patient's SINA wallet and consume credits through one captured turn,
     * so both `wallet` and `wallet/usage` have non-trivial figures to assert.
     */
    private function spendWallet(Tenant $tenant, Patient $patient, int $grant, int $consume): void
    {
        $tenantId = (string) $tenant->getKey();
        $patientId = (string) $patient->getKey();
        $consultationId = (string) Str::ulid();
        $wallets = app(SinaWalletService::class);

        $wallets->grant($tenantId, $patientId, $grant, 'poc grant', 'grant-'.$patientId);
        $wallets->reserve($tenantId, $patientId, $consultationId, $consume, 'reserve-'.$patientId);
        $wallets->capture($tenantId, $patientId, $consultationId, $consume, 'capture-'.$patientId, [
            'provider' => 'openai_compatible',
            'model_name' => 'gpt-poc',
        ]);
    }

    // ========================================= 1. The end-to-end journey

    public function test_a_mobile_client_can_login_select_book_and_download_a_released_report(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->bindTenant($tenant);
        $user = $this->patientUser($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Journey');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Perjalanan']);
        $version = $this->releasedReportFor($tenant, $patient, 'Cabang Terbit');

        // 1) LOGIN — exchange credentials for a clinic-bound bearer token.
        $login = $this->postJson(self::BASE.'/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'goodbarber-poc',
        ])->assertOk();

        $login->assertJsonPath('data.requires_clinic_selection', false);
        $this->assertSame((string) $tenant->getKey(), $login->json('data.clinic.id'));

        $token = (string) $login->json('data.token');
        $this->assertNotEmpty($token);

        // 2) IDENTITY — the token resolves to the same clinic and own patient.
        $this->withToken($token)->getJson(self::BASE.'/me')
            ->assertOk()
            ->assertJsonPath('data.clinic.id', (string) $tenant->getKey())
            ->assertJsonPath('data.patient.full_name', 'Synthetic Journey');

        // 3) CHOOSE CLINIC — the eligible list is exposed and can be re-selected.
        $this->withToken($token)->getJson(self::BASE.'/clinics')
            ->assertOk()
            ->assertJsonPath('meta.current_clinic_id', (string) $tenant->getKey());

        $token = (string) $this->withToken($token)->postJson(self::BASE.'/clinics/select', [
            'clinic_id' => (string) $tenant->getKey(),
        ])->assertOk()->json('data.token');

        // 4) BOOK — pick a branch then create the appointment.
        $branches = $this->withToken($token)->getJson(self::BASE.'/directory/branches')->assertOk();
        $this->assertContains((string) $branch->getKey(), array_column($branches->json('data'), 'id'));

        $booking = $this->withToken($token)->postJson(self::BASE.'/appointments', [
            'branch_id' => (string) $branch->getKey(),
            'starts_at' => now()->addDay()->toIso8601String(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ])->assertStatus(201);

        $bookingId = (string) $booking->json('data.id');
        $this->assertNotEmpty($bookingId);

        // 5) RESULTS — the released report is listed, then streams as a PDF.
        $list = $this->withToken($token)->getJson(self::BASE.'/reports')->assertOk();
        $this->assertContains((string) $version->getKey(), array_column($list->json('data'), 'id'));

        $download = $this->withToken($token)->get(self::BASE.'/reports/'.$version->getKey().'/download');
        $download->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));

        // The appointment is visible in the caller's own history too.
        $this->withToken($token)->getJson(self::BASE.'/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $bookingId);

        // 6) WALLET — the SINA credit balance and usage are exposed on the same token.
        $this->spendWallet($tenant, $patient, 20, 3);

        $this->withToken($token)->getJson(self::BASE.'/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 17)
            ->assertJsonPath('data.wallet.reserved_credits', 0)
            ->assertJsonPath('data.wallet.lifetime_consumed_credits', 3)
            ->assertJsonPath('data.usage_total_credits', 3)
            ->assertJsonPath('data.usage_turns', 1);

        $this->withToken($token)->getJson(self::BASE.'/wallet/usage')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.credit_units', 3)
            ->assertJsonPath('data.0.provider', 'openai_compatible');
    }

    // ========================================= 2. Boundaries along the journey

    public function test_the_token_is_confined_to_its_own_clinic_across_the_whole_journey(): void
    {
        // Clinic A — where the token is issued.
        $tenantA = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);
        $this->bindTenant($tenantA);
        $user = $this->patientUser($tenantA);
        $this->registerPatient($tenantA, $user, 'Synthetic Own A');
        $branchA = $this->createBranch($tenantA, ['name' => 'Cabang A']);

        // Clinic B — a foreign tenant this token must never reach.
        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);
        $this->bindTenant($tenantB);
        $foreignPatient = $this->registerPatient($tenantB, null, 'Synthetic Foreign B');
        $branchB = $this->createBranch($tenantB, ['name' => 'Cabang B']);
        $foreignReport = $this->releasedReportFor($tenantB, $foreignPatient, 'Cabang B Terbit');

        $token = app(PatientApiTokenIssuer::class)->issue($user, (string) $tenantA->getKey(), 'poc')['plain'];

        // The foreign report never resolves in the bound clinic...
        $this->withToken($token)->get(self::BASE.'/reports/'.$foreignReport->getKey().'/download')
            ->assertNotFound();

        // ...the foreign branch cannot be booked (it does not exist here)...
        $this->withToken($token)->postJson(self::BASE.'/appointments', [
            'branch_id' => (string) $branchB->getKey(),
            'starts_at' => now()->addDay()->toIso8601String(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ])->assertStatus(422);

        // ...while the clinic's OWN branch still books normally.
        $this->withToken($token)->postJson(self::BASE.'/appointments', [
            'branch_id' => (string) $branchA->getKey(),
            'starts_at' => now()->addDay()->toIso8601String(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ])->assertStatus(201);

        // The wallet is keyed by (clinic, patient): a fat balance granted to a
        // foreign clinic's patient is invisible to this token, which sees only
        // its OWN empty wallet and zero usage.
        app(SinaWalletService::class)->grant(
            (string) $tenantB->getKey(),
            (string) $foreignPatient->getKey(),
            99,
            'foreign clinic grant',
            'foreign-clinic-wallet',
        );

        $this->withToken($token)->getJson(self::BASE.'/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 0)
            ->assertJsonPath('data.usage_total_credits', 0)
            ->assertJsonPath('data.usage_turns', 0);

        $this->withToken($token)->getJson(self::BASE.'/wallet/usage')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_a_patient_cannot_reach_another_patients_appointments_orders_or_reports(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Isolation');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Isolation');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Isolasi']);

        $foreignAppointment = app(AppointmentScheduler::class)->create([
            'patient_id' => $foreign->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $user);

        $foreignReport = $this->releasedReportFor($tenant, $foreign, 'Cabang Asing Terbit');

        $token = app(PatientApiTokenIssuer::class)->issue($user, (string) $tenant->getKey(), 'poc')['plain'];

        // A foreign appointment id is indistinguishable from a missing one.
        $this->withToken($token)->getJson(self::BASE.'/appointments/'.$foreignAppointment->getKey())
            ->assertStatus(404);

        // A foreign patient's released report is never downloadable.
        $this->withToken($token)->get(self::BASE.'/reports/'.$foreignReport->getKey().'/download')
            ->assertStatus(404);

        // The own (empty) lists expose nothing of the foreign patient.
        $this->withToken($token)->getJson(self::BASE.'/appointments')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        $this->withToken($token)->getJson(self::BASE.'/reports')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        // Sanity: the own patient really has no records.
        $this->bindTenant($tenant);
        $this->assertSame(0, $own->appointments()->count());
    }

    // =============================== 3. Release gate + API↔portal parity

    public function test_a_report_that_has_not_been_released_is_not_available_to_the_patient(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Pending Release');

        // Approved by a reviewer, but the clinician has NOT released it yet.
        $pending = $this->approvedReportFor($tenant, $patient, 'Cabang Belum Terbit');
        $this->assertFalse($pending->isReleased());

        $token = app(PatientApiTokenIssuer::class)->issue($user, (string) $tenant->getKey(), 'poc')['plain'];

        // The unpublished report is neither listed...
        $this->withToken($token)->getJson(self::BASE.'/reports')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        // ...nor downloadable.
        $this->withToken($token)->get(self::BASE.'/reports/'.$pending->getKey().'/download')
            ->assertNotFound();
    }

    public function test_the_api_wallet_matches_the_patient_portal_dompet_token(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        $user = $this->patientUser($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Wallet Parity');

        $this->spendWallet($tenant, $patient, 117, 13);

        $token = app(PatientApiTokenIssuer::class)->issue($user, (string) $tenant->getKey(), 'poc')['plain'];

        // The API is the authoritative mobile view of the SINA credit wallet...
        $this->withToken($token)->getJson(self::BASE.'/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet.balance_credits', 104)
            ->assertJsonPath('data.wallet.reserved_credits', 0)
            ->assertJsonPath('data.wallet.lifetime_consumed_credits', 13)
            ->assertJsonPath('data.usage_total_credits', 13)
            ->assertJsonPath('data.usage_turns', 1);

        $this->withToken($token)->getJson(self::BASE.'/wallet/usage')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.credit_units', 13);

        // ...and the Filament "Dompet Token" page shows the very same figures for
        // the same patient — the two surfaces read one wallet. The patient session
        // guard is pinned explicitly: the earlier API calls left `auth:api` as the
        // default guard.
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $this->actingAs($user, 'web')->get('/patient/dompet-token')
            ->assertOk()
            ->assertSeeText('104')
            ->assertSeeText(__('patient_wallet.credits_used', ['credits' => 13]));
        // The credit records really belong to the acting clinic + patient only.
        $this->bindTenant($tenant);
        $this->assertSame(1, SinaUsageRecord::query()->where('patient_id', $patient->getKey())->count());
    }
}
