<?php

namespace Tests\Feature\Modules;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Services\ConsultationManager;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Exceptions\ReviewException;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerRegistry;
use Modules\Practitioners\Services\PractitionerWorkload;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS7.C — linking practitioners to appointments, consultations and LBA
 * examinations, and enforcing independent reviewer authorization.
 *
 * Exercises the shared assignment gate and the appointment intake paths (a
 * checked-in appointment is handed into a consultation / examination using the
 * existing foreign keys, idempotently), the practitioner eligibility of the
 * consultation assignee and the examination operator, the tenant isolation of
 * the intake, and the reviewer-eligibility gate that refuses a registered
 * practitioner whose professional standing has lapsed. Only synthetic
 * identities, patients and tasks are used (DECISIONS.md PRV-01).
 */
class PractitionerClinicalIntegrationTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
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

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function patient(Tenant $tenant, User $owner): Patient
    {
        return app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Intake Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);
    }

    /**
     * A verified, branch-authorized practitioner plus their branch and the
     * clinical identity behind them.
     *
     * @return array{0: Practitioner, 1: TenantBranch, 2: User}
     */
    private function readyPractitioner(Tenant $tenant, User $owner): array
    {
        $clinical = $this->clinical($tenant);
        $branch = $this->createBranch($tenant);

        $registry = app(PractitionerRegistry::class);

        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Integration',
        ]);
        $registry->verify($owner, $practitioner);
        $registry->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        return [$practitioner->fresh(), $branch, $clinical];
    }

    /**
     * A booked appointment assigned to a practitioner.
     */
    private function appointment(
        Tenant $tenant,
        User $owner,
        TenantBranch $branch,
        ?string $staffUserId,
        string $status = Appointment::STATUS_CHECKED_IN,
        ?string $at = null,
    ): Appointment {
        $patient = $this->patient($tenant, $owner);

        $starts = $at !== null
            ? Carbon::parse($at, 'UTC')
            : now()->addDay()->setTime(9, 0);

        return app(AppointmentScheduler::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'staff_user_id' => $staffUserId,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => $status,
        ], $owner);
    }

    /**
     * A submitted assessment plus a brand, ready for a review decision.
     */
    private function submitted(Tenant $tenant, User $owner, User $operator, ?TenantBranch $branch = null): Assessment
    {
        $branch ??= $this->createBranch($tenant);
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

    // ------------------------------------------------ Appointment → intake

    public function test_a_checked_in_appointment_is_handed_into_a_consultation(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $appointment = $this->appointment($tenant, $owner, $branch, (string) $practitioner->user_id);

        $consultation = app(ConsultationManager::class)->intakeFromAppointment($appointment, $owner);

        $this->assertSame((string) $appointment->getKey(), (string) $consultation->appointment_id);
        $this->assertSame((string) $appointment->patient_id, (string) $consultation->patient_id);
        $this->assertSame((string) $branch->getKey(), (string) $consultation->branch_id);
        $this->assertSame((string) $practitioner->user_id, (string) $consultation->staff_user_id);
        $this->assertDatabaseHas('consultations', [
            'id' => $consultation->getKey(),
            'appointment_id' => (string) $appointment->getKey(),
        ], 'tenant');
    }

    public function test_intake_into_a_consultation_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $appointment = $this->appointment($tenant, $owner, $branch, (string) $practitioner->user_id);

        $first = app(ConsultationManager::class)->intakeFromAppointment($appointment, $owner);
        $second = app(ConsultationManager::class)->intakeFromAppointment($appointment, $owner);

        $this->assertSame((string) $first->getKey(), (string) $second->getKey());
        $this->assertSame(
            1,
            Consultation::query()->where('appointment_id', $appointment->getKey())->count(),
        );
    }

    public function test_intake_into_a_consultation_requires_a_checked_in_appointment(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $appointment = $this->appointment(
            $tenant,
            $owner,
            $branch,
            (string) $practitioner->user_id,
            Appointment::STATUS_SCHEDULED,
        );

        $this->expectException(ConsultationException::class);

        app(ConsultationManager::class)->intakeFromAppointment($appointment, $owner);
    }

    public function test_a_foreign_appointment_is_refused(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->bindTenant($tenantA);

        [$practitionerA, $branchA] = $this->readyPractitioner($tenantA, $ownerA);
        $appointment = $this->appointment($tenantA, $ownerA, $branchA, (string) $practitionerA->user_id);

        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->bindTenant($tenantB);

        $this->expectException(ConsultationException::class);

        app(ConsultationManager::class)->intakeFromAppointment($appointment, $ownerA);
    }

    // ------------------------------------------------ Appointment → examination

    public function test_a_checked_in_appointment_is_handed_into_an_examination(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $appointment = $this->appointment($tenant, $owner, $branch, (string) $practitioner->user_id);

        $examination = app(ExaminationRegistrar::class)->intakeFromAppointment($appointment, $owner);

        $this->assertSame((string) $appointment->getKey(), (string) $examination->appointment_id);
        $this->assertSame((string) $appointment->patient_id, (string) $examination->patient_id);
        $this->assertSame((string) $branch->getKey(), (string) $examination->branch_id);
        $this->assertSame((string) $practitioner->user_id, (string) $examination->operator_user_id);
        $this->assertDatabaseHas('lba_examinations', [
            'id' => $examination->getKey(),
            'appointment_id' => (string) $appointment->getKey(),
        ], 'tenant');
    }

    public function test_intake_into_an_examination_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $appointment = $this->appointment($tenant, $owner, $branch, (string) $practitioner->user_id);

        $first = app(ExaminationRegistrar::class)->intakeFromAppointment($appointment, $owner);
        $second = app(ExaminationRegistrar::class)->intakeFromAppointment($appointment, $owner);

        $this->assertSame((string) $first->getKey(), (string) $second->getKey());
        $this->assertSame(
            1,
            Examination::query()->where('appointment_id', $appointment->getKey())->count(),
        );
    }

    public function test_intake_into_an_examination_requires_a_checked_in_appointment(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);

        $appointment = $this->appointment(
            $tenant,
            $owner,
            $branch,
            (string) $practitioner->user_id,
            Appointment::STATUS_SCHEDULED,
        );

        $this->expectException(ExaminationException::class);

        app(ExaminationRegistrar::class)->intakeFromAppointment($appointment, $owner);
    }

    // ------------------------------------------------------ Assignment gates

    public function test_a_consultation_refuses_a_suspended_practitioner(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);
        app(PractitionerRegistry::class)->suspend($owner, $practitioner);

        $patient = $this->patient($tenant, $owner);

        $this->expectException(ConsultationException::class);

        app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'staff_user_id' => (string) $practitioner->user_id,
        ], $owner);
    }

    public function test_a_consultation_permits_a_non_roster_member(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);
        $staff = $this->clinical($tenant);
        $patient = $this->patient($tenant, $owner);

        $consultation = app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'staff_user_id' => (string) $staff->getKey(),
        ], $owner);

        $this->assertSame((string) $staff->getKey(), (string) $consultation->staff_user_id);
    }

    public function test_an_examination_refuses_a_suspended_operator(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant, $owner);
        app(PractitionerRegistry::class)->suspend($owner, $practitioner);

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

    // ----------------------------------------------------------- Reviewer gate

    public function test_a_registered_practitioner_with_lapsed_standing_cannot_review(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->clinical($tenant);
        $reviewer = $this->clinical($tenant, [RoleKey::REVIEWER]);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $registry = app(PractitionerRegistry::class);
        $practitioner = $registry->register($owner, (string) $reviewer->getKey(), [
            'display_name' => 'Dr Reviewer',
        ]);
        $registry->verify($owner, $practitioner);
        $registry->suspend($owner, $practitioner);

        $assessment = $this->submitted($tenant, $owner, $operator);

        $this->expectException(ReviewException::class);

        app(AssessmentReviewer::class)->returnForChanges($assessment, $reviewer, 'Please revise.');
    }

    public function test_a_verified_practitioner_reviewer_may_review(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->clinical($tenant);
        $reviewer = $this->clinical($tenant, [RoleKey::REVIEWER]);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $registry = app(PractitionerRegistry::class);
        $practitioner = $registry->register($owner, (string) $reviewer->getKey(), [
            'display_name' => 'Dr Reviewer',
        ]);
        $registry->verify($owner, $practitioner);

        $assessment = $this->submitted($tenant, $owner, $operator);

        $returned = app(AssessmentReviewer::class)->returnForChanges($assessment, $reviewer, 'Please revise.');

        $this->assertSame(Assessment::STATUS_RETURNED_FOR_CHANGES, $returned->status);
    }

    public function test_a_non_roster_reviewer_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->clinical($tenant);
        $reviewer = $this->clinical($tenant, [RoleKey::REVIEWER]);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $assessment = $this->submitted($tenant, $owner, $operator);

        $returned = app(AssessmentReviewer::class)->returnForChanges($assessment, $reviewer, 'Please revise.');

        $this->assertSame(Assessment::STATUS_RETURNED_FOR_CHANGES, $returned->status);
    }

    public function test_a_lapsed_practitioner_reviewer_cannot_approve(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->clinical($tenant);
        $reviewer = $this->clinical($tenant, [RoleKey::REVIEWER]);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $registry = app(PractitionerRegistry::class);
        $practitioner = $registry->register($owner, (string) $reviewer->getKey(), [
            'display_name' => 'Dr Approver',
        ]);
        $registry->verify($owner, $practitioner);
        $registry->suspend($owner, $practitioner);

        $assessment = $this->submitted($tenant, $owner, $operator);

        $this->expectException(ReviewException::class);

        app(AssessmentReviewer::class)->approve($assessment, (string) $tenant->getKey(), $reviewer);
    }

    // --------------------------------------------------------------- Workload

    public function test_the_workload_counts_are_read_from_the_tenant_tables(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch, $clinical] = $this->readyPractitioner($tenant, $owner);
        $practitionerId = (string) $practitioner->user_id;

        // Two upcoming occupying appointments — one today, one tomorrow.
        $this->appointment($tenant, $owner, $branch, $practitionerId, Appointment::STATUS_SCHEDULED, '2026-06-15 11:00:00');
        $this->appointment($tenant, $owner, $branch, $practitionerId, Appointment::STATUS_SCHEDULED, '2026-06-16 09:00:00');

        // One open examination and one submitted assessment for that practitioner.
        $this->submitted($tenant, $owner, $clinical, $branch);

        // One consultation completed today for that practitioner.
        $consultation = app(ConsultationManager::class)->create([
            'patient_id' => $this->patient($tenant, $owner)->getKey(),
            'branch_id' => $branch->getKey(),
            'staff_user_id' => $practitionerId,
        ], $owner);
        app(ConsultationManager::class)->transition($consultation, Consultation::STATUS_IN_PROGRESS, $owner);
        app(ConsultationManager::class)->transition($consultation, Consultation::STATUS_COMPLETED, $owner);

        $snapshot = app(PractitionerWorkload::class)->snapshot($practitionerId);

        $this->assertSame(2, $snapshot['upcoming_appointments']);
        $this->assertSame(1, $snapshot['today_sessions']);
        $this->assertSame(1, $snapshot['pending_examinations']);
        $this->assertSame(1, $snapshot['pending_reviews']);
        $this->assertSame(1, $snapshot['completed_consultations']);
    }

    public function test_the_workload_fails_closed_without_a_tenant_context(): void
    {
        Tenant::factory()->create();

        $snapshot = app(PractitionerWorkload::class)->snapshot();

        $this->assertSame([null, null, null, null, null], array_values($snapshot));
    }

    public function test_the_workload_is_isolated_between_tenants(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->bindTenant($tenantA);

        [$practitionerA, $branchA] = $this->readyPractitioner($tenantA, $ownerA);
        $this->appointment($tenantA, $ownerA, $branchA, (string) $practitionerA->user_id, Appointment::STATUS_SCHEDULED, '2026-06-15 11:00:00');

        $this->assertSame(1, app(PractitionerWorkload::class)->snapshot()['today_sessions']);

        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->bindTenant($tenantB);

        $snapshot = app(PractitionerWorkload::class)->snapshot();

        $this->assertSame(0, $snapshot['today_sessions']);
        $this->assertSame(0, $snapshot['upcoming_appointments']);
    }
}
