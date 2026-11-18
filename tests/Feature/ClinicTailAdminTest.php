<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationEvent;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\LBA\Services\ExaminationWorkflow;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

class ClinicTailAdminTest extends TestCase
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
        $this->teardownTenantDatabases();
        parent::tearDown();
    }

    public function test_workspace_requires_an_eligible_clinic_identity_and_ready_database(): void
    {
        $path = '/clinic/workspace';
        $this->get($path)->assertRedirect('/login');
        $this->actingAs(User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create())->get($path)->assertForbidden();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->actingAs($owner)->get($path)->assertForbidden();

        $tenant = Tenant::factory()->withoutDatabase()->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->get($path)->assertStatus(503);

        $this->provisionTenantDatabase($tenant);
        $this->artisan('lba:tenant:migrate', ['tenant' => $tenant->id])->assertSuccessful();
        $this->get($path)->assertOk()->assertSee($tenant->name);
        $this->get($path.'/patients')->assertForbidden();
        $this->post($path.'/switch-tenant', ['tenant_id' => Tenant::factory()->create()->id])->assertForbidden();
    }

    public function test_patient_and_appointment_lists_use_the_verified_tenant_and_branch_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $staff = User::factory()->role(RoleKey::RECEPTIONIST)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($staff, $tenant, [RoleKey::RECEPTIONIST]);
        $this->provisionTenantDatabase($tenant);

        foreach (['patients', 'appointments', 'lba'] as $module) {
            RegisteredModule::factory()->forKey($module)->enabled()->create();
            ModuleInstallation::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->enabled()->create();
            ModuleEntitlement::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->granted()->create();
        }

        $this->tenantDatabases()->configure($tenant->id);
        $allowed = $this->createBranch($tenant, ['name' => 'Visible Branch']);
        $other = $this->createBranch($tenant, ['name' => 'Restricted Branch']);
        $this->assignStaff($owner, $staff, $allowed->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'TailAdmin Example Patient', 'status' => Patient::STATUS_ACTIVE], $owner);

        foreach ([$allowed, $other] as $offset => $branch) {
            $starts = now()->addDay()->setTime(9 + $offset * 2, 0);
            app(AppointmentScheduler::class)->create([
                'patient_id' => $patient->id,
                'branch_id' => $branch->id,
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHour(),
                'service_type' => Appointment::SERVICE_CONSULTATION,
            ], $owner);
        }

        $examinationPatient = app(PatientRegistrar::class)->create(['full_name' => 'TailAdmin LBA Patient', 'status' => Patient::STATUS_ACTIVE], $owner);
        app(ExaminationRegistrar::class)->create([
            'patient_id' => $examinationPatient->id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $allowed->id,
        ], $owner);

        $this->actingAs($owner)->get('/clinic/workspace/examinations?search=LBA')->assertOk()->assertSee($examinationPatient->full_name);
        $this->get('/clinic/workspace/reports')->assertOk()->assertSee(__('lba.reports.empty'));

        $booking = '/clinic/workspace/appointments';
        $this->get($booking.'/create?search=Example')->assertOk()->assertSee($patient->full_name);
        $startsAt = now()->addDays(2)->setTime(9, 0);
        $this->post($booking, [
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'branch_id' => $allowed->id,
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'ends_at' => $startsAt->copy()->addHour()->format('Y-m-d\TH:i'),
        ])->assertRedirect($booking);

        $exams = '/clinic/workspace/examinations';
        $this->get($exams.'/create')->assertOk()->assertSee($patient->full_name);
        $this->post($exams, [
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'branch_id' => $allowed->id,
            'mode' => Examination::MODE_MANUAL,
        ])->assertRedirect($exams);
        $this->tenantDatabases()->configure($tenant->id);
        $created = Examination::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame(Examination::STATUS_DRAFT, $created->status);
        $this->assertNull($created->consent_id);

        $this->actingAs($staff)->get('/clinic/workspace/patients?search=Example')->assertOk()->assertSee($patient->full_name);
        $this->get('/clinic/workspace/appointments')->assertOk()->assertSee($allowed->name)->assertDontSee($other->name);
        $create = '/clinic/workspace/patients';
        $this->get($create.'/create')->assertOk()->assertSee($allowed->name)->assertDontSee($other->name);
        $registration = [
            'tenant_id' => $tenant->id,
            'full_name' => 'New TailAdmin Patient',
            'registered_branch_id' => $allowed->id,
            'status' => Patient::STATUS_ACTIVE,
        ];
        $this->postJson($create, array_replace($registration, ['tenant_id' => Tenant::factory()->create()->id]))->assertForbidden();
        $this->postJson($create, array_replace($registration, ['registered_branch_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('registered_branch_id');
        $this->post($create, $registration)->assertRedirect($create);
        $this->tenantDatabases()->configure($tenant->id);
        $registered = Patient::query()->where('full_name', $registration['full_name'])->sole();
        $this->assertSame($allowed->id, $registered->registered_branch_id);
        $this->assertTrue(PatientAuditLog::query()->where('patient_id', $registered->id)->exists());

        $this->get('/clinic/workspace/examinations')->assertForbidden();
        $this->get('/clinic/workspace/reports')->assertForbidden();
        $this->post('/clinic/workspace/switch-branch', ['tenant_id' => $tenant->id, 'branch_id' => $other->id])->assertForbidden();
    }

    public function test_examination_case_detail_edit_and_transition_enforce_scope_and_authority(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $operator = User::factory()->role(RoleKey::LBA_OPERATOR)->create();
        $receptionist = User::factory()->role(RoleKey::RECEPTIONIST)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($operator, $tenant, [RoleKey::LBA_OPERATOR]);
        $this->giveMembership($receptionist, $tenant, [RoleKey::RECEPTIONIST]);
        $this->provisionTenantDatabase($tenant);

        foreach (['patients', 'lba'] as $module) {
            RegisteredModule::factory()->forKey($module)->enabled()->create();
            ModuleInstallation::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->enabled()->create();
            ModuleEntitlement::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->granted()->create();
        }

        $this->tenantDatabases()->configure($tenant->id);
        $allowed = $this->createBranch($tenant, ['name' => 'Case Branch']);
        $other = $this->createBranch($tenant, ['name' => 'Foreign Branch']);
        $this->assignStaff($owner, $operator, $allowed->id);
        $this->assignStaff($owner, $receptionist, $allowed->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Case Detail Patient', 'status' => Patient::STATUS_ACTIVE], $owner);

        $case = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $allowed->id,
            'notes' => 'Initial note',
        ], $owner);

        $foreignBranch = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $other->id,
        ], $owner);

        // A real examination that lives in ANOTHER tenant's isolated database.
        $tenantTwo = Tenant::factory()->create();
        $ownerTwo = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($ownerTwo, $tenantTwo, [RoleKey::TENANT_OWNER]);
        $this->provisionTenantDatabase($tenantTwo);
        $this->tenantDatabases()->configure($tenantTwo->id);
        $branchTwo = $this->createBranch($tenantTwo);
        $patientTwo = app(PatientRegistrar::class)->create(['full_name' => 'Foreign Tenant Patient', 'status' => Patient::STATUS_ACTIVE], $ownerTwo);
        $foreignTenant = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patientTwo->id,
            'branch_id' => $branchTwo->id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $ownerTwo);

        $show = fn (Examination $record): string => route('clinic.tailadmin.examinations.show', $record);

        // The permitted operator opens the case dossier.
        $this->actingAs($operator)->get($show($case))->assertOk()->assertSee('Case Detail Patient');

        // A case in a branch the operator may not operate in is forbidden.
        $this->get($show($foreignBranch))->assertForbidden();

        // A case in another tenant's database never resolves in this tenant.
        $this->get($show($foreignTenant))->assertNotFound();

        // An identity without exam.read cannot open the dossier at all.
        $this->actingAs($receptionist)->get($show($case))->assertForbidden();

        // Edit is permitted and bounded to the branch the identity may operate in.
        $this->actingAs($operator)->get($show($case).'/edit')->assertOk();
        $this->put($show($case), [
            'branch_id' => $allowed->id,
            'operator_user_id' => $operator->id,
            'mode' => Examination::MODE_MANUAL,
            'notes' => 'Reviewed note',
        ])->assertRedirect($show($case));
        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame('Reviewed note', $case->refresh()->notes);

        // A branch the identity may not operate in is rejected by server validation.
        $this->put($show($case), [
            'branch_id' => $other->id,
            'operator_user_id' => $operator->id,
            'mode' => Examination::MODE_MANUAL,
        ])->assertSessionHasErrors('branch_id');

        // Moving the case is gated server-side at every step.
        $this->tenantDatabases()->configure($tenant->id);
        $reviewable = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $allowed->id,
        ], $owner);
        $this->tenantDatabases()->configure($tenant->id);
        app(ExaminationWorkflow::class)->transition($reviewable, Examination::STATUS_UPLOADED, $owner);
        app(ExaminationWorkflow::class)->transition($reviewable, Examination::STATUS_MANUAL_ANNOTATION, $owner);
        $transition = route('clinic.tailadmin.examinations.transition', $reviewable);

        // An identity without exam.update may not move the case.
        $this->actingAs($receptionist)
            ->post($transition, ['to' => Examination::STATUS_REVIEW_REQUIRED])
            ->assertForbidden();

        // A draft case may not jump straight to review, even for a permitted operator.
        $this->tenantDatabases()->configure($tenant->id);
        $draftOnly = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $allowed->id,
        ], $owner);
        $this->actingAs($operator)
            ->post(route('clinic.tailadmin.examinations.transition', $draftOnly), ['to' => Examination::STATUS_REVIEW_REQUIRED])
            ->assertForbidden();

        // The legal transition succeeds and is recorded in the append-only log.
        $this->actingAs($operator)
            ->post($transition, ['to' => Examination::STATUS_REVIEW_REQUIRED])
            ->assertRedirect($show($reviewable));
        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(Examination::STATUS_REVIEW_REQUIRED, $reviewable->refresh()->status);
        $this->assertTrue(ExaminationEvent::query()
            ->where('examination_id', $reviewable->id)
            ->where('to_status', Examination::STATUS_REVIEW_REQUIRED)
            ->exists());
    }

    public function test_private_image_intake_requires_consent_and_editable_case(): void
    {
        Storage::fake('lba');

        $tenant = Tenant::factory()->create();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $operator = User::factory()->role(RoleKey::LBA_OPERATOR)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($operator, $tenant, [RoleKey::LBA_OPERATOR]);
        $this->provisionTenantDatabase($tenant);

        foreach (['patients', 'lba'] as $module) {
            RegisteredModule::factory()->forKey($module)->enabled()->create();
            ModuleInstallation::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->enabled()->create();
            ModuleEntitlement::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->granted()->create();
        }

        $this->tenantDatabases()->configure($tenant->id);
        $allowed = $this->createBranch($tenant, ['name' => 'Imaging Branch']);
        $other = $this->createBranch($tenant, ['name' => 'Other Branch']);
        $this->assignStaff($owner, $operator, $allowed->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Imaging Patient', 'status' => Patient::STATUS_ACTIVE], $owner);

        $store = fn (Examination $record): string => route('clinic.tailadmin.examinations.images.store', $record);

        // No recorded image-processing consent => the private store refuses the upload.
        $noConsent = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->id,
            'mode' => Examination::MODE_MANUAL,
            'branch_id' => $allowed->id,
        ], $owner);

        $this->actingAs($operator)
            ->post($store($noConsent), ['image' => UploadedFile::fake()->image('scan.jpg', 120, 120)])
            ->assertSessionHasErrors('image');
        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(0, ExaminationImage::query()->where('examination_id', $noConsent->id)->count());

        // With a granted consent the still is written to the PRIVATE disk only.
        $withConsent = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $allowed->id,
        ], $owner);

        $this->actingAs($operator)
            ->post($store($withConsent), ['image' => UploadedFile::fake()->image('scan.jpg', 120, 120)])
            ->assertRedirect(route('clinic.tailadmin.examinations.show', $withConsent));

        $this->tenantDatabases()->configure($tenant->id);
        $stored = ExaminationImage::query()->where('examination_id', $withConsent->id)->sole();
        $this->assertSame(Examination::STATUS_UPLOADED, $withConsent->refresh()->status);
        Storage::disk('lba')->assertExists($stored->object_key);

        // A case in a branch the operator may not operate in refuses the upload outright.
        $foreignBranch = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->id,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $other->id,
        ], $owner);

        $this->actingAs($operator)
            ->post($store($foreignBranch), ['image' => UploadedFile::fake()->image('scan.jpg', 120, 120)])
            ->assertForbidden();
    }
}
