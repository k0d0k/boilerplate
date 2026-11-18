<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentEvidenceLink;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 7A/7B via the Clinic TailAdmin (Blade) surface — assessment recording,
 * human review and the explicit, private report release.
 *
 * These are the AUTHORIZATION and separation-of-duties essentials of the
 * TailAdmin counterpart of the Filament pages: every route and action must
 * re-derive, on the server, the module entitlement, the acting role, the
 * verified tenant context and the branch scope. UI visibility is never the
 * boundary. Every identity, patient and image here is synthetic.
 */
class ClinicAssessmentReviewTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

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
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    /**
     * @param  list<string>  $modules
     */
    private function activateModules(Tenant $tenant, array $modules = ['patients', 'lba']): void
    {
        foreach ($modules as $module) {
            if (! RegisteredModule::query()->where('module_key', $module)->exists()) {
                RegisteredModule::factory()->forKey($module)->enabled()->create();
            }

            ModuleInstallation::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->enabled()->create();
            ModuleEntitlement::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->granted()->create();
        }
    }

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    private function linkBrand(Tenant $tenant): Brand
    {
        $brand = Brand::query()->create([
            'name' => 'Brand '.Str::upper(Str::random(4)),
            'slug' => 'brand-'.Str::lower(Str::random(6)),
            'status' => Brand::STATUS_ACTIVE,
        ]);

        $brand->tenants()->attach($tenant->getKey(), ['is_primary' => true]);

        return $brand;
    }

    private function makeExamination(Tenant $tenant, User $owner, string $branchId): Examination
    {
        $this->tenantDatabases()->configure($tenant->id);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Assessment Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $branchId,
        ], $owner);
    }

    /**
     * A submitted (awaiting review) assessment recorded by `$recorder`, with a
     * brand selected so it is reviewable.
     */
    private function submittedAssessment(Tenant $tenant, User $owner, User $recorder, string $branchId): Assessment
    {
        $examination = $this->makeExamination($tenant, $owner, $branchId);
        $component = app(AssessmentRecorder::class);
        $assessment = $component->openFor($examination, $recorder);
        $brand = $this->linkBrand($tenant);

        $component->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_YELLOW, $recorder);
        $component->selectBrand($assessment, (string) $tenant->getKey(), (string) $brand->getKey(), $recorder);
        $component->submitForReview($assessment->fresh(), $recorder);

        return $assessment->fresh();
    }

    // ============================== 1. Recording, submit, lock and scope

    public function test_assessment_recording_and_submission_enforce_scope_and_lock(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        $this->tenantDatabases()->configure($tenant->id);
        $allowed = $this->createBranch($tenant, ['name' => 'Assess Branch']);
        $other = $this->createBranch($tenant, ['name' => 'Foreign Assess Branch']);
        $this->assignStaff($owner, $operator, $allowed->id);
        $this->assignStaff($owner, $receptionist, $allowed->id);

        $examination = $this->makeExamination($tenant, $owner, $allowed->id);
        $foreignBranch = $this->makeExamination($tenant, $owner, $other->id);

        // A real examination that lives in ANOTHER tenant's isolated database.
        $tenantTwo = Tenant::factory()->create();
        $ownerTwo = $this->member($tenantTwo, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantTwo);
        $this->tenantDatabases()->configure($tenantTwo->id);
        $branchTwo = $this->createBranch($tenantTwo);
        $foreignTenant = $this->makeExamination($tenantTwo, $ownerTwo, $branchTwo->id);

        $assessmentUrl = fn (Examination $record): string => route('clinic.tailadmin.assessments.show', $record);

        // An identity without exam.read cannot open the recording surface.
        $this->actingAs($receptionist)->get($assessmentUrl($examination))->assertForbidden();

        // The permitted operator opens the recording surface (creating the draft).
        $this->actingAs($operator)->get($assessmentUrl($examination))
            ->assertOk()
            ->assertSee('Synthetic Assessment Patient');

        // A case in another branch the operator may not operate in is forbidden.
        $this->get($assessmentUrl($foreignBranch))->assertForbidden();

        // A case in another tenant's database never resolves here.
        $this->get($assessmentUrl($foreignTenant))->assertNotFound();

        // Recording a category writes through the domain recorder.
        $this->post(route('clinic.tailadmin.assessments.category', $examination), [
            'indicator_key' => 'asthma_paru_tbc',
            'category' => AssessmentItem::CATEGORY_YELLOW,
        ])->assertRedirect();
        $this->get($assessmentUrl($examination))->assertOk();

        $this->tenantDatabases()->configure($tenant->id);
        $assessment = Assessment::query()->where('examination_id', $examination->id)->sole();
        $this->assertSame(
            AssessmentItem::CATEGORY_YELLOW,
            $assessment->items()->where('indicator_key', 'asthma_paru_tbc')->sole()->category,
        );

        // A private still is uploaded (consent is recorded on the examination).
        $this->actingAs($operator)
            ->post(route('clinic.tailadmin.examinations.images.store', $examination), [
                'image' => UploadedFile::fake()->image('scan.jpg', 120, 120),
            ])
            ->assertRedirect(route('clinic.tailadmin.examinations.show', $examination));

        $this->tenantDatabases()->configure($tenant->id);
        $image = $examination->refresh()->images()->sole();

        // The still can be attached to an indicator as supporting evidence.
        $this->post(route('clinic.tailadmin.assessments.evidence', $examination), [
            'indicator_key' => 'asthma_paru_tbc',
            'image_id' => $image->id,
        ])->assertRedirect();

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertTrue(AssessmentEvidenceLink::query()
            ->where('assessment_id', $assessment->id)
            ->where('evidence_id', $image->id)
            ->exists());

        // A foreign (non-existent) image id is refused by the domain recorder.
        $this->post(route('clinic.tailadmin.assessments.evidence', $examination), [
            'indicator_key' => 'asthma_paru_tbc',
            'image_id' => (string) Str::ulid(),
        ])->assertSessionHasErrors('image_id');

        // Select the report brand, then submit for human review.
        $brand = $this->linkBrand($tenant);
        $this->post(route('clinic.tailadmin.assessments.brand', $examination), [
            'brand_id' => $brand->id,
        ])->assertRedirect();

        $this->post(route('clinic.tailadmin.assessments.submit', $examination))
            ->assertRedirect($assessmentUrl($examination));

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(Assessment::STATUS_SUBMITTED_FOR_REVIEW, $assessment->refresh()->status);

        // Once submitted the assessment is locked: recording is refused outright.
        $this->actingAs($operator)->post(route('clinic.tailadmin.assessments.category', $examination), [
            'indicator_key' => 'asthma_paru_tbc',
            'category' => AssessmentItem::CATEGORY_GREEN,
        ])->assertForbidden();
    }

    // ============================== 2. Review, self-approval and release

    public function test_review_decisions_self_approval_and_release_are_enforced(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        $this->tenantDatabases()->configure($tenant->id);
        $allowed = $this->createBranch($tenant, ['name' => 'Review Branch']);
        $other = $this->createBranch($tenant, ['name' => 'Foreign Review Branch']);
        $this->assignStaff($owner, $operator, $allowed->id);
        $this->assignStaff($owner, $reviewer, $allowed->id);

        // A reviewed-candidate assessment recorded by the operator.
        $assessment = $this->submittedAssessment($tenant, $owner, $operator, $allowed->id);

        // A submitted assessment living in a branch the reviewer may not operate in.
        $foreignBranch = $this->submittedAssessment($tenant, $owner, $operator, $other->id);

        // A submitted assessment in ANOTHER tenant's isolated database.
        $tenantTwo = Tenant::factory()->create();
        $ownerTwo = $this->member($tenantTwo, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantTwo);
        $this->activateModules($tenantTwo);
        $this->tenantDatabases()->configure($tenantTwo->id);
        $branchTwo = $this->createBranch($tenantTwo);
        $foreignTenant = $this->submittedAssessment($tenantTwo, $ownerTwo, $ownerTwo, $branchTwo->id);

        $inbox = route('clinic.tailadmin.reviews.index');
        $show = fn (Assessment $record): string => route('clinic.tailadmin.reviews.show', $record);

        // An operator without exam.review never reaches the reviewer surface.
        $this->actingAs($operator)->get($inbox)->assertForbidden();
        $this->get($show($assessment))->assertForbidden();
        $this->post(route('clinic.tailadmin.reviews.decide', $assessment), ['decision' => 'approve'])
            ->assertForbidden();

        // The reviewer reaches the inbox and a case in their own branch.
        $this->actingAs($reviewer)->get($inbox)->assertOk();
        $this->get($show($assessment))->assertOk();

        // A case in another branch the reviewer may not operate in is forbidden.
        $this->get($show($foreignBranch))->assertForbidden();

        // A case in another tenant's database never resolves here.
        $this->get($show($foreignTenant))->assertNotFound();

        // Separation of duties: the reviewer cannot approve their own recording.
        $selfRecorded = $this->submittedAssessment($tenant, $owner, $reviewer, $allowed->id);
        $this->actingAs($reviewer)
            ->post(route('clinic.tailadmin.reviews.decide', $selfRecorded), ['decision' => 'approve'])
            ->assertSessionHasErrors('decision');
        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(Assessment::STATUS_SUBMITTED_FOR_REVIEW, $selfRecorded->refresh()->status);

        // A distinct reviewer approves; the first immutable report version is issued.
        $this->actingAs($reviewer)
            ->post(route('clinic.tailadmin.reviews.decide', $assessment), ['decision' => 'approve'])
            ->assertRedirect($show($assessment));

        $this->tenantDatabases()->configure($tenant->id);
        $assessment->refresh();
        $this->assertSame(Assessment::STATUS_REVIEWED, $assessment->status);
        $version = $assessment->reportVersions()->first();
        $this->assertInstanceOf(ReportVersion::class, $version);
        $this->assertFalse($version->isReleased());

        // Releasing a report requires report.approve — the operator is refused.
        $release = route('clinic.tailadmin.reviews.release', [$assessment, $version]);
        $this->actingAs($operator)->post($release)->assertForbidden();

        // A report id belonging to ANOTHER assessment never resolves (404).
        $this->actingAs($reviewer)
            ->post(route('clinic.tailadmin.reviews.release', [$selfRecorded, $version]))
            ->assertNotFound();

        // The authorized reviewer explicitly releases THIS version to the patient.
        $this->actingAs($reviewer)->post($release)->assertRedirect($show($assessment));

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertTrue($version->refresh()->isReleased());
    }
}
