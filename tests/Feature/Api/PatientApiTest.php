<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\PatientApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Api\PatientApiTokenIssuer;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ReportEvent;
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
 * Phase 8B — Patient REST API (focused security + contract essentials).
 *
 * Exercises the admission boundary of the mobile API: bearer-token
 * authentication scoped to ONE clinic, tenant + patient isolation, the
 * release gate on reports, request validation, pagination and rate limiting.
 * Only synthetic identities, patients and clinical objects are used
 * (DECISIONS.md PRV-01).
 */
class PatientApiTest extends TestCase
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

        return app(AssessmentReviewer::class)->approve($assessment->fresh(), (string) $tenant->getKey(), $reviewer);
    }

    private function releasedReportFor(Tenant $tenant, Patient $patient, string $branchName): ReportVersion
    {
        $version = $this->approvedReportFor($tenant, $patient, $branchName);
        $actor = $this->member($tenant, RoleKey::REVIEWER);

        $this->bindTenant($tenant);

        return app(AssessmentReviewer::class)->releaseToPatient($version, (string) $tenant->getKey(), $actor);
    }

    // ========================================= 1. Authentication

    public function test_login_rejects_invalid_credentials(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->patientUser($tenant);

        $this->postJson(self::BASE.'/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_login_is_denied_for_non_patient_panel_accounts(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);

        $this->postJson(self::BASE.'/login', [
            'email' => $operator->email,
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_login_requires_an_active_clinic_membership(): void
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();

        $this->postJson(self::BASE.'/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'no_clinic');
    }

    public function test_login_requires_explicit_clinic_selection_when_multiple(): void
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, Tenant::factory()->create(['name' => 'Klinik Alpha']), [RoleKey::PATIENT_CUSTOMER]);
        $this->giveMembership($user, Tenant::factory()->create(['name' => 'Klinik Beta']), [RoleKey::PATIENT_CUSTOMER]);

        $response = $this->postJson(self::BASE.'/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.requires_clinic_selection', true);

        $this->assertCount(2, $response->json('data.clinics'));
        $this->assertNull($response->json('data.token'));
    }

    public function test_login_auto_selects_the_single_clinic_and_issues_a_token(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->patientUser($tenant);

        $response = $this->postJson(self::BASE.'/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('Bearer', $response->json('data.token_type'));
        $this->assertSame((string) $tenant->getKey(), $response->json('data.clinic.id') ?? null);

        // Only the hash is ever persisted, never the raw secret.
        $this->assertDatabaseMissing('patient_api_tokens', [
            'token' => $response->json('data.token'),
        ]);
        $this->assertSame(
            1,
            PatientApiToken::query()->where('token', PatientApiToken::hash($response->json('data.token')))->count(),
        );
    }

    public function test_protected_endpoints_require_a_valid_bearer_token(): void
    {
        $this->getJson(self::BASE.'/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->withToken('not-a-real-token')->getJson(self::BASE.'/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_token_is_invalidated_when_the_membership_is_revoked(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $user = $this->patientUser($tenant);

        $token = $this->issuedToken($user, $tenant);

        // The token authenticates while the membership is active...
        $this->withToken($token)->getJson(self::BASE.'/me')->assertOk();

        // ...and fails closed the moment the membership is revoked.
        $user->tenantMemberships()->update(['status' => 'revoked']);

        // The request-scoped guard caches the resolved user for the duration of
        // one request; production starts a fresh request, so reset it to mimic
        // the next inbound call.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson(self::BASE.'/me')->assertStatus(401);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $user = $this->patientUser($tenant);

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->postJson(self::BASE.'/logout')->assertOk();

        // Fresh request boundary, as production would serve it.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson(self::BASE.'/me')->assertStatus(401);
    }

    // ========================================= 2. Isolation

    public function test_a_token_is_scoped_to_exactly_its_own_clinic(): void
    {
        $tenantA = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantA);
        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $tenantA, [RoleKey::PATIENT_CUSTOMER]);
        $this->giveMembership($user, $tenantB, [RoleKey::PATIENT_CUSTOMER]);

        $tokenA = $this->issuedToken($user, $tenantA);

        $response = $this->withToken($tokenA)->getJson(self::BASE.'/me')->assertOk();

        // The clinic reported is ALWAYS the one bound to the token, never a
        // value the client could influence.
        $this->assertSame((string) $tenantA->getKey(), $response->json('data.clinic.id'));
        $this->assertNotSame((string) $tenantB->getKey(), $response->json('data.clinic.id'));
    }

    public function test_a_token_cannot_read_another_clinics_released_report(): void
    {
        $tenantA = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);

        $user = $this->patientUser($tenantA);

        $this->bindTenant($tenantA);
        $own = $this->registerPatient($tenantA, $user, 'Synthetic Own A');
        $ownVersion = $this->releasedReportFor($tenantA, $own, 'Cabang A');

        $this->bindTenant($tenantB);
        $foreign = $this->registerPatient($tenantB, null, 'Synthetic Foreign B');
        $foreignVersion = $this->releasedReportFor($tenantB, $foreign, 'Cabang B');

        $tokenA = $this->issuedToken($user, $tenantA);

        // The exact foreign version id never resolves in the bound clinic.
        $this->withToken($tokenA)
            ->getJson(self::BASE.'/reports/'.$foreignVersion->getKey().'/download')
            ->assertStatus(404);

        // The caller's own released report still streams.
        $this->withToken($tokenA)
            ->get(self::BASE.'/reports/'.$ownVersion->getKey().'/download')
            ->assertOk();
    }

    // ========================================= 3. Profile

    public function test_profile_update_only_accepts_self_service_contact_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, $user, 'Synthetic Profile');

        $token = $this->issuedToken($user, $tenant);

        $this->withToken($token)->getJson(self::BASE.'/profile')
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Synthetic Profile');

        // An attempt to rewrite the clinical-of-record name through the API is
        // ignored — only the whitelisted contact fields are applied.
        $this->withToken($token)->putJson(self::BASE.'/profile', [
            'full_name' => 'Hijacked Name',
            'phone' => '0812345678',
            'country' => 'ID',
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '0812345678')
            ->assertJsonPath('data.full_name', 'Synthetic Profile');

        $this->bindTenant($tenant);
        $this->assertSame('Synthetic Profile', $patient->fresh()->full_name);
    }

    // ========================================= 4. Directory

    public function test_the_branch_directory_lists_only_active_branches_of_the_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $this->createBranch($tenant, ['name' => 'Cabang Aktif']);
        $this->createBranch($tenant, ['name' => 'Cabang Nonaktif', 'status' => 'inactive']);

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)->getJson(self::BASE.'/directory/branches')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Cabang Aktif', $names);
        $this->assertNotContains('Cabang Nonaktif', $names);
    }

    // ========================================= 5. Appointments

    public function test_appointment_booking_validates_input_and_creates_an_own_appointment(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Booking');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Booking']);

        $token = $this->issuedToken($user, $tenant);

        // An unknown service type is rejected with the shared error envelope.
        $this->withToken($token)->postJson(self::BASE.'/appointments', [
            'branch_id' => $branch->getKey(),
            'starts_at' => now()->addDay()->toIso8601String(),
            'service_type' => 'not-a-service',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $this->withToken($token)->postJson(self::BASE.'/appointments', [
            'branch_id' => $branch->getKey(),
            'starts_at' => now()->addDay()->toIso8601String(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', Appointment::STATUS_SCHEDULED)
            ->assertJsonPath('data.branch.id', (string) $branch->getKey());
    }

    public function test_appointment_history_is_paginated_and_self_only(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Appt');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Appt');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Appt']);

        $starts = now()->addDay();

        $ownAppointment = app(AppointmentScheduler::class)->create([
            'patient_id' => $own->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes(30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $user);

        app(AppointmentScheduler::class)->create([
            'patient_id' => $foreign->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts->copy()->addHour(),
            'ends_at' => $starts->copy()->addHour()->addMinutes(30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $user);

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)->getJson(self::BASE.'/appointments')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('meta.pagination.total'));
        $this->assertSame((string) $ownAppointment->getKey(), (string) $response->json('data.0.id'));
    }

    public function test_a_foreign_appointment_cannot_be_shown_or_cancelled(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, $user, 'Synthetic Own Cancel');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Cancel');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Cancel']);

        $starts = now()->addDay();

        $foreignAppointment = app(AppointmentScheduler::class)->create([
            'patient_id' => $foreign->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes(30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $user);

        $token = $this->issuedToken($user, $tenant);

        // A foreign id is indistinguishable from a missing one.
        $this->withToken($token)->getJson(self::BASE.'/appointments/'.$foreignAppointment->getKey())
            ->assertStatus(404);

        $this->withToken($token)->postJson(self::BASE.'/appointments/'.$foreignAppointment->getKey().'/cancel')
            ->assertStatus(404);

        $this->bindTenant($tenant);
        $this->assertSame(Appointment::STATUS_SCHEDULED, $foreignAppointment->fresh()->status);
    }

    public function test_the_availability_endpoint_returns_the_branch_slots(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant, [
            'name' => 'Cabang Jadwal',
            'timezone' => config('app.timezone'),
            'opens_at' => '09:00',
            'closes_at' => '11:00',
            'slot_minutes' => 30,
        ]);

        $token = $this->issuedToken($user, $tenant);
        $date = now()->addDays(3)->format('Y-m-d');

        $response = $this->withToken($token)
            ->getJson(self::BASE.'/directory/branches/'.$branch->getKey().'/availability?date='.$date)
            ->assertOk();

        $this->assertCount(4, $response->json('data'));
        $this->assertSame($date, $response->json('meta.date'));
        $this->assertSame(30, $response->json('meta.slot_minutes'));

        // A malformed date is rejected with the shared error envelope.
        $this->withToken($token)
            ->getJson(self::BASE.'/directory/branches/'.$branch->getKey().'/availability?date=not-a-date')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_reschedule_is_self_only_and_blocked_for_terminal_appointments(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $own = $this->registerPatient($tenant, $user, 'Synthetic Reschedule');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Reschedule');
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Reschedule']);

        $starts = now()->addDay();

        $appointment = app(AppointmentScheduler::class)->create([
            'patient_id' => $own->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes(30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $user);

        $foreignAppointment = app(AppointmentScheduler::class)->create([
            'patient_id' => $foreign->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts->copy()->addHours(3),
            'ends_at' => $starts->copy()->addHours(4),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $user);

        $token = $this->issuedToken($user, $tenant);

        // A foreign id is indistinguishable from a missing one.
        $this->withToken($token)->postJson(
            self::BASE.'/appointments/'.$foreignAppointment->getKey().'/reschedule',
            ['starts_at' => $starts->copy()->addDays(5)->toIso8601String()],
        )->assertStatus(404);

        // An own appointment moves, keeping its service type when not supplied.
        $newStart = $starts->copy()->addDays(5)->setTime(10, 0);

        $this->withToken($token)->postJson(
            self::BASE.'/appointments/'.$appointment->getKey().'/reschedule',
            ['starts_at' => $newStart->toIso8601String()],
        )
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_SCHEDULED)
            ->assertJsonPath('data.service_type', Appointment::SERVICE_CONSULTATION);

        $this->bindTenant($tenant);
        $this->assertTrue($appointment->fresh()->starts_at->equalTo($newStart));

        // A terminal appointment can never be rescheduled.
        app(AppointmentScheduler::class)->transition($appointment->fresh(), Appointment::STATUS_CANCELLED, $user);

        $this->withToken($token)->postJson(
            self::BASE.'/appointments/'.$appointment->getKey().'/reschedule',
            ['starts_at' => $starts->copy()->addDays(6)->toIso8601String()],
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_transition');
    }

    // ========================================= 6. Released reports

    public function test_the_report_list_exposes_only_own_released_versions(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Own List');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign List');

        // Own released — visible. Own approved-only — invisible. Foreign
        // released — invisible.
        $released = $this->releasedReportFor($tenant, $own, 'Cabang Terbit');
        $this->approvedReportFor($tenant, $own, 'Cabang Belum Terbit');
        $this->releasedReportFor($tenant, $foreign, 'Cabang Asing');

        $token = $this->issuedToken($user, $tenant);

        $response = $this->withToken($token)->getJson(self::BASE.'/reports')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains((string) $released->getKey(), $ids);
        $this->assertCount(1, $ids);
    }

    public function test_download_is_denied_for_unreleased_and_foreign_and_audited_when_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Download');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Download');

        $unreleased = $this->approvedReportFor($tenant, $own, 'Cabang Belum Terbit');
        $ownReleased = $this->releasedReportFor($tenant, $own, 'Cabang Sendiri');
        $foreignReleased = $this->releasedReportFor($tenant, $foreign, 'Cabang Asing');

        $token = $this->issuedToken($user, $tenant);

        // Approved but not explicitly released: fails closed.
        $this->withToken($token)->getJson(self::BASE.'/reports/'.$unreleased->getKey().'/download')
            ->assertStatus(404);

        // Another patient's released report: denied.
        $this->withToken($token)->getJson(self::BASE.'/reports/'.$foreignReleased->getKey().'/download')
            ->assertStatus(404);

        // Own released report streams as a private PDF...
        $download = $this->withToken($token)->get(self::BASE.'/reports/'.$ownReleased->getKey().'/download');
        $download->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));

        // ...and the access is recorded in the append-only audit trail.
        $this->bindTenant($tenant);
        $this->assertSame(
            1,
            ReportEvent::query()
                ->where('report_version_id', $ownReleased->getKey())
                ->where('action', ReportEvent::ACTION_DOWNLOADED)
                ->count(),
        );
    }

    // ========================================= 7. Rate limiting

    public function test_login_is_rate_limited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->patientUser($tenant);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(self::BASE.'/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson(self::BASE.'/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
