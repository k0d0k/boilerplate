<?php

namespace Tests\Feature\Modules;

use App\Filament\Clinic\Widgets\PractitionerWorkloadWidget;
use App\Filament\Patient\Pages\Booking;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Exceptions\ReviewException;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\LBA\Services\MicroscopyImageStore;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Models\PractitionerAuditLog;
use Modules\Practitioners\Models\PractitionerCredential;
use Modules\Practitioners\Models\PractitionerScheduleException;
use Modules\Practitioners\Services\PractitionerAvailability;
use Modules\Practitioners\Services\PractitionerBooking;
use Modules\Practitioners\Services\PractitionerRegistry;
use Modules\Practitioners\Services\PractitionerScheduleRegistry;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS7.D — negative RBAC, tenant/branch isolation, concurrency and the modern
 * operational surface of the practitioner workstream.
 *
 * This suite complements the WS7.A/B/C suites with the cases the specification
 * calls out explicitly for WS7.D:
 *  - role / tenant / branch NEGATIVES (Principal never reads clinical data;
 *    a marketing/commerce staff member may neither assign doctors nor review
 *    LBA; lapsed standing blocks future booking; a reservation never crosses a
 *    clinic);
 *  - the specific CONCURRENCY cases (races for a final slot / branch capacity,
 *    rescheduling, leave boundaries, timezone boundaries);
 *  - the WS6 camera-import integration (an examination operator must be an
 *    authorized practitioner, while the safe image intake is unchanged);
 *  - the live operational dashboard widget and the patient clinician/slot picker.
 *
 * Only synthetic identities, patients, schedules and 1×1 images are used.
 */
class PractitionerOperationsTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE_PRACTITIONERS = 'practitioners';

    private const MODULE_LBA = 'lba';

    /** A real, decodable 1×1 PNG (no GD dependency). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Storage::fake('lba');
        Storage::fake('lba-reports');
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

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

    /**
     * @param  list<string>  $roleKeys
     */
    private function clinical(Tenant $tenant, array $roleKeys = [RoleKey::CONSULTANT]): User
    {
        $user = User::factory()->role($roleKeys[0])->create();
        $this->giveMembership($user, $tenant, $roleKeys);

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

    private function activateModule(string $moduleKey, Tenant $tenant): void
    {
        if (! RegisteredModule::query()->where('module_key', $moduleKey)->exists()) {
            RegisteredModule::factory()->forKey($moduleKey)->enabled()->create();
        }

        ModuleInstallation::factory()
            ->forModule($moduleKey)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule($moduleKey)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    /**
     * A verified, branch-authorized practitioner plus their own branch and the
     * clinical identity behind them.
     *
     * @return array{0: Practitioner, 1: TenantBranch, 2: User}
     */
    private function readyPractitioner(Tenant $tenant, User $owner): array
    {
        $clinical = $this->clinical($tenant);
        $branch = $this->createBranch($tenant, ['timezone' => 'UTC']);

        $registry = app(PractitionerRegistry::class);

        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Operations',
        ]);
        $registry->verify($owner, $practitioner);
        $registry->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        return [$practitioner->fresh(), $branch, $clinical];
    }

    /**
     * An additional verified, branch-authorized practitioner at an EXISTING
     * branch (used for capacity races).
     */
    private function practitionerAt(Tenant $tenant, User $owner, TenantBranch $branch, string $name): Practitioner
    {
        $clinical = $this->clinical($tenant);

        $registry = app(PractitionerRegistry::class);

        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => $name,
        ]);
        $registry->verify($owner, $practitioner);
        $registry->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        return $practitioner->fresh();
    }

    private function scheduleWindow(User $actor, Practitioner $practitioner, TenantBranch $branch, string $start, string $end): void
    {
        app(PractitionerScheduleRegistry::class)->addWindow($actor, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => Carbon::MONDAY,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    }

    private function patient(Tenant $tenant, User $owner, string $name = 'Synthetic Operations Patient'): Patient
    {
        return app(PatientRegistrar::class)->create([
            'full_name' => $name,
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);
    }

    private function boundPatient(Tenant $tenant, Patient $patient, User $account, User $actor): void
    {
        $this->bindTenant($tenant);

        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $actor);

        app(PatientAccountLinker::class)->redeemInvitation($account, $code);
    }

    private function futureMonday(): Carbon
    {
        return Carbon::now('UTC')->addWeek()->startOfWeek(Carbon::MONDAY)->setTime(12, 0);
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, (string) base64_decode(self::PNG));

        return new UploadedFile($path, 'capture.png', 'image/png', null, true);
    }

    /**
     * A submitted assessment (plus brand) ready for a review decision.
     */
    private function submitted(Tenant $tenant, User $owner, User $operator, TenantBranch $branch): Assessment
    {
        $patient = $this->patient($tenant, $owner);

        $examination = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $operator);

        $brand = Brand::query()->create([
            'name' => 'Brand '.Str::upper(Str::random(4)),
            'slug' => 'brand-'.Str::lower(Str::random(6)),
            'status' => Brand::STATUS_ACTIVE,
        ]);
        $brand->tenants()->attach($tenant->getKey(), ['is_primary' => true]);

        $recorder = app(AssessmentRecorder::class);
        $assessment = $recorder->openFor($examination, $operator);
        $recorder->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_YELLOW, $operator);
        $recorder->selectBrand($assessment, (string) $tenant->getKey(), (string) $brand->getKey(), $operator);
        $recorder->submitForReview($assessment->fresh(), $operator);

        return $assessment->fresh();
    }

    // =============================================== 1. Negative RBAC / isolation

    public function test_the_principal_cannot_stream_a_clinical_image(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModule(self::MODULE_LBA, $tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);
        $patient = $this->patient($tenant, $owner);

        $examination = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $owner);

        $this->bindTenant($tenant);
        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        // A platform governor never holds a clinic context nor any clinical
        // permission, so the private image stream fails closed.
        $principal = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();
        $this->grantPrincipalRole($principal, RoleKey::PRINCIPAL_SUPER_ADMIN);

        $this->actingAs($principal)
            ->get(route('lba.images.stream', ['image' => $image->getKey()]))
            ->assertForbidden();
    }

    public function test_a_non_clinical_clinic_role_cannot_stream_a_clinical_image(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activateModule(self::MODULE_LBA, $tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);
        $patient = $this->patient($tenant, $owner);

        $examination = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $owner);

        $this->bindTenant($tenant);
        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        // A front-desk identity reaches the Clinic panel but holds no
        // `exam.read` permission, so the image is never streamed.
        $this->actingAs($receptionist)
            ->get(route('lba.images.stream', ['image' => $image->getKey()]))
            ->assertForbidden();
    }

    public function test_commerce_staff_cannot_authorize_a_practitioner_to_a_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $marketing = $this->member($tenant, RoleKey::COMMERCE_MANAGER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);

        $practitioner = app(PractitionerRegistry::class)->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Marketing No',
        ]);

        // Brand/commerce staff have no `practitioner.profile.manage`, so they
        // can never tell the clinic where a doctor may practise.
        $this->expectException(PractitionerException::class);

        app(PractitionerRegistry::class)->authorizeBranch($marketing, $practitioner, (string) $branch->getKey());
    }

    public function test_commerce_staff_cannot_review_an_lba_assessment(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->clinical($tenant);
        $marketing = $this->member($tenant, RoleKey::COMMERCE_MANAGER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);
        $assessment = $this->submitted($tenant, $owner, $operator, $branch);

        // A non-clinical staff identity holds no `exam.review`, so no clinical
        // decision can ever be taken by marketing/commerce.
        $this->expectException(ReviewException::class);

        app(AssessmentReviewer::class)->returnForChanges($assessment, $marketing, 'Not a clinician.');
    }

    public function test_a_suspended_practitioner_cannot_reserve_a_slot(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);
        $this->scheduleWindow($owner, $practitioner, $branch, '09:00', '12:00');

        app(PractitionerRegistry::class)->suspend($owner, $practitioner);

        $patient = $this->patient($tenant, $owner);
        $monday = $this->futureMonday()->startOfDay()->setTime(9, 0);

        $this->expectException(AppointmentConflictException::class);

        app(PractitionerBooking::class)->reserve(
            $practitioner->fresh(),
            $branch,
            $patient,
            $monday,
            Appointment::SERVICE_CONSULTATION,
            actor: $owner,
        );
    }

    public function test_a_lapsed_verification_cannot_reserve_a_slot(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant, ['timezone' => 'UTC']);
        $registry = app(PractitionerRegistry::class);

        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Expired',
        ]);
        // Verified in the past: the standing has lapsed.
        $registry->verify($owner, $practitioner, now()->subDay());
        $registry->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        $patient = $this->patient($tenant, $owner);
        $monday = $this->futureMonday()->startOfDay()->setTime(9, 0);

        $this->expectException(AppointmentConflictException::class);

        app(PractitionerBooking::class)->reserve(
            $practitioner->fresh(),
            $branch,
            $patient,
            $monday,
            Appointment::SERVICE_CONSULTATION,
            actor: $owner,
        );
    }

    public function test_a_revoked_branch_authorization_blocks_reservation(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);
        $this->scheduleWindow($owner, $practitioner, $branch, '09:00', '12:00');

        app(PractitionerRegistry::class)->revokeBranch($owner, $practitioner, (string) $branch->getKey());

        $patient = $this->patient($tenant, $owner);
        $monday = $this->futureMonday()->startOfDay()->setTime(9, 0);

        $this->expectException(AppointmentConflictException::class);

        app(PractitionerBooking::class)->reserve(
            $practitioner->fresh(),
            $branch,
            $patient,
            $monday,
            Appointment::SERVICE_CONSULTATION,
            actor: $owner,
        );
    }

    public function test_a_reservation_is_isolated_between_clinics(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->bindTenant($tenantA);

        [$practitionerA, $branchA] = $this->readyPractitioner($tenantA, $ownerA);
        $this->scheduleWindow($ownerA, $practitionerA, $branchA, '09:00', '12:00');

        $tenantB = Tenant::factory()->create();
        $ownerB = $this->member($tenantB, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantB);
        $this->bindTenant($tenantB);

        // Clinic B's database holds no practitioner of its own...
        $this->assertSame(0, Practitioner::query()->count());

        $patientB = $this->patient($tenantB, $ownerB);
        $monday = $this->futureMonday()->startOfDay()->setTime(9, 0);

        // ...and can never reserve clinic A's practitioner / branch.
        $this->expectException(AppointmentConflictException::class);

        app(PractitionerBooking::class)->reserve(
            $practitionerA,
            $branchA,
            $patientB,
            $monday,
            Appointment::SERVICE_CONSULTATION,
            actor: $ownerB,
        );
    }

    public function test_a_revoked_credential_is_retained_non_destructively(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $registry = app(PractitionerRegistry::class);

        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Credential',
        ]);

        $credential = $registry->addCredential($owner, $practitioner, [
            'credential_type' => PractitionerCredential::TYPE_LICENSE,
            'reference_number' => 'MOH-998877',
        ]);
        $registry->verifyCredential($owner, $credential, now()->addYear());

        $revoked = $registry->revokeCredential($owner, $credential->fresh(), 'Lapsed licence.');

        $this->assertTrue($revoked->isRevoked());
        $this->assertNotNull($revoked->revoked_at);

        // Non-destructive: the row (and its history) survive.
        $this->assertDatabaseHas('practitioner_credentials', [
            'id' => $credential->getKey(),
            'status' => PractitionerCredential::STATUS_REVOKED,
        ], 'tenant');
        $this->assertDatabaseHas('practitioner_audit_logs', [
            'practitioner_id' => $practitioner->getKey(),
            'action' => PractitionerAuditLog::ACTION_CREDENTIAL_REVOKED,
        ], 'tenant');
    }

    // ===================================================== 2. Concurrency cases

    public function test_branch_capacity_bounds_concurrent_practitioner_reservations(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        // A single-chair room: at most one occupying appointment at a time.
        $branch = $this->createBranch($tenant, ['timezone' => 'UTC', 'capacity' => 1]);

        $practitionerA = $this->practitionerAt($tenant, $owner, $branch, 'Dr Chair A');
        $practitionerB = $this->practitionerAt($tenant, $owner, $branch, 'Dr Chair B');

        $this->scheduleWindow($owner, $practitionerA, $branch, '09:00', '12:00');
        $this->scheduleWindow($owner, $practitionerB, $branch, '09:00', '12:00');

        $first = $this->patient($tenant, $owner, 'Synthetic First Chair');
        $second = $this->patient($tenant, $owner, 'Synthetic Second Chair');

        $monday = $this->futureMonday()->startOfDay()->setTime(9, 0);

        app(PractitionerBooking::class)->reserve(
            $practitionerA,
            $branch,
            $first,
            $monday,
            Appointment::SERVICE_CONSULTATION,
            actor: $owner,
        );

        // A different practitioner racing for the same instant still loses to
        // the branch capacity guard.
        $this->expectException(AppointmentConflictException::class);

        app(PractitionerBooking::class)->reserve(
            $practitionerB,
            $branch,
            $second,
            $monday,
            Appointment::SERVICE_CONSULTATION,
            actor: $owner,
        );
    }

    public function test_rescheduling_onto_an_occupied_practitioner_slot_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $patient = $this->patient($tenant, $owner);
        $monday = $this->futureMonday()->startOfDay();

        $scheduler = app(AppointmentScheduler::class);

        $morning = $scheduler->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'staff_user_id' => (string) $practitioner->user_id,
            'starts_at' => $monday->copy()->setTime(9, 0),
            'ends_at' => $monday->copy()->setTime(9, 30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $owner);

        $later = $scheduler->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'staff_user_id' => (string) $practitioner->user_id,
            'starts_at' => $monday->copy()->setTime(11, 0),
            'ends_at' => $monday->copy()->setTime(11, 30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $owner);

        // Rescheduling onto the occupied 09:00 slot is refused...
        try {
            $scheduler->reschedule($later, [
                'starts_at' => $monday->copy()->setTime(9, 0),
                'ends_at' => $monday->copy()->setTime(9, 30),
            ], $owner);
            $this->fail('Rescheduling onto an occupied practitioner slot must be refused.');
        } catch (AppointmentConflictException) {
            // expected
        }

        // ...while a free slot succeeds.
        $moved = $scheduler->reschedule($later->fresh(), [
            'starts_at' => $monday->copy()->setTime(10, 0),
            'ends_at' => $monday->copy()->setTime(10, 30),
        ], $owner);

        $this->assertSame('10:00', $moved->fresh()->starts_at->format('H:i'));
        $this->assertSame(
            '09:00',
            $morning->fresh()->starts_at->format('H:i'),
        );
    }

    public function test_a_leave_exception_boundary_is_respected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);
        $this->scheduleWindow($owner, $practitioner, $branch, '09:00', '12:00');

        $monday = $this->futureMonday()->startOfDay();

        app(PractitionerScheduleRegistry::class)->recordException($owner, $practitioner, [
            'type' => PractitionerScheduleException::TYPE_LEAVE,
            'starts_at' => $monday->copy()->setTime(9, 30)->toIso8601String(),
            'ends_at' => $monday->copy()->setTime(10, 0)->toIso8601String(),
        ]);

        $availability = app(PractitionerAvailability::class);
        $practitioner = $practitioner->fresh();

        // A slot that ENDS exactly when leave begins is still bookable...
        $this->assertTrue($availability->covers(
            $practitioner,
            $branch,
            $monday->copy()->setTime(9, 0),
            $monday->copy()->setTime(9, 30),
        ));

        // ...the leave instant itself is blocked...
        $this->assertFalse($availability->covers(
            $practitioner,
            $branch,
            $monday->copy()->setTime(9, 30),
            $monday->copy()->setTime(10, 0),
        ));

        // ...and a slot that STARTS exactly when leave ends is bookable again.
        $this->assertTrue($availability->covers(
            $practitioner,
            $branch,
            $monday->copy()->setTime(10, 0),
            $monday->copy()->setTime(10, 30),
        ));
    }

    public function test_slots_are_computed_in_the_branch_timezone(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant, [
            'timezone' => 'Asia/Jakarta',
            'opens_at' => '09:00',
            'closes_at' => '10:00',
            'slot_minutes' => 30,
        ]);

        $clinical = $this->clinical($tenant);
        $registry = app(PractitionerRegistry::class);
        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Jakarta',
        ]);
        $registry->verify($owner, $practitioner);
        $registry->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        $this->scheduleWindow($owner, $practitioner->fresh(), $branch, '09:00', '10:00');

        $slots = app(PractitionerAvailability::class)->slotsForDate(
            $practitioner->fresh(),
            $branch,
            $this->futureMonday(),
        );

        // 09:00–10:00 local (UTC+7) yields exactly two 30-minute slots, and the
        // stored instant is expressed in UTC (02:00Z / 02:30Z).
        $this->assertCount(2, $slots);
        $this->assertSame('09:00', Carbon::parse($slots[0]['starts_at'])->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertSame('09:30', Carbon::parse($slots[1]['starts_at'])->setTimezone('Asia/Jakarta')->format('H:i'));
        $this->assertSame('02:00', Carbon::parse($slots[0]['starts_at'])->setTimezone('UTC')->format('H:i'));
    }

    // ============================================ 3. WS6 camera-import integration

    public function test_a_registered_practitioner_operator_must_be_authorized_for_the_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);

        // Verified, but NOT authorized for this branch.
        $registry = app(PractitionerRegistry::class);
        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Camera',
        ]);
        $registry->verify($owner, $practitioner);

        $patient = $this->patient($tenant, $owner);

        $this->expectException(ExaminationException::class);

        app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'operator_user_id' => (string) $practitioner->user_id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $owner);
    }

    public function test_an_authorized_practitioner_operator_can_capture_and_store_an_image(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModule(self::MODULE_LBA, $tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $patient = $this->patient($tenant, $owner);

        $examination = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'operator_user_id' => (string) $practitioner->user_id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $owner);

        $this->assertSame((string) $practitioner->user_id, (string) $examination->operator_user_id);

        // The safe, content-validated intake is unchanged for an authorized
        // operator: a real image is stored as an immutable original.
        $this->bindTenant($tenant);
        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        $this->assertSame(ExaminationImage::SOURCE_MANUAL_IMPORT, $image->source);
        $this->assertDatabaseHas('lba_images', [
            'id' => $image->getKey(),
            'examination_id' => $examination->getKey(),
        ], 'tenant');
    }

    // ================================================= 4. Modern operational UI

    public function test_the_workload_widget_renders_an_honest_empty_state(): void
    {
        // Without a verified tenant context every figure renders the honest
        // empty state — never a fabricated operational number.
        Livewire::test(PractitionerWorkloadWidget::class)
            ->assertSee(__('lba.kpi.workload.upcoming_label'))
            ->assertSee(__('lba.kpi.workload.pending_reviews_label'))
            ->assertSee(__('lba.widget.workload_heading'))
            ->assertSee('—');
    }

    public function test_the_workload_widget_is_registered_on_the_clinic_panel(): void
    {
        $widgets = array_map(
            static fn (string|WidgetConfiguration $widget): string => $widget instanceof WidgetConfiguration
                ? $widget->widget
                : $widget,
            Filament::getPanel('clinic')->getWidgets(),
        );

        $this->assertContains(PractitionerWorkloadWidget::class, $widgets);
    }

    public function test_the_patient_booking_page_offers_a_verified_clinician(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $account = $this->patientUser($tenant);
        $patient = $this->patient($tenant, $owner, 'Synthetic Booker');
        $this->boundPatient($tenant, $patient, $account, $owner);

        $this->actingAs($account);
        $this->bindTenant($tenant);

        // Filament 5 pages cannot be driven through `Livewire::test()->set()`
        // (the follow-up re-render POST fails snapshot validation — see
        // {@see \Tests\Support\DrivesPrincipalPanel}), so the picker is read
        // from the real page instance, exactly as the repository's other
        // Filament page tests do.
        $page = new Booking;
        $page->branchId = (string) $branch->getKey();
        $page->serviceType = Appointment::SERVICE_CONSULTATION;

        $options = $page->clinicianOptions();

        $this->assertArrayHasKey((string) $practitioner->getKey(), $options);
        $this->assertStringContainsString('Dr Operations', $options[(string) $practitioner->getKey()]);
    }

    public function test_the_patient_booking_page_hides_a_suspended_clinician(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        app(PractitionerRegistry::class)->suspend($owner, $practitioner);

        $account = $this->patientUser($tenant);
        $patient = $this->patient($tenant, $owner, 'Synthetic Suspended Viewer');
        $this->boundPatient($tenant, $patient, $account, $owner);

        $this->actingAs($account);
        $this->bindTenant($tenant);

        $page = new Booking;
        $page->branchId = (string) $branch->getKey();
        $page->serviceType = Appointment::SERVICE_CONSULTATION;

        $this->assertArrayNotHasKey((string) $practitioner->getKey(), $page->clinicianOptions());
    }
}
