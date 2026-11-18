<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Api\PatientApiTokenIssuer;
use App\Platform\Branches\Models\TenantBranch;
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
use Modules\Appointments\Models\Appointment;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ReportVersion;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Permintaan B / Bagian 2 — one clinic case end to end through the NORMAL
 * TailAdmin routes (not the service layer), proving the modules are actually
 * wired to the pages a person signs in to use.
 *
 * Journey: examination location → appointment → intake → Manual LBA examination
 * → private image upload → assessment recording (reached through the case-detail
 * connector) → review by a DIFFERENT reviewer → report release → the patient
 * opens their own released result. A second pass runs the AI and Hybrid variants
 * through the same normal routes with the synthetic mock adapter and pins that a
 * Manual examination never starts AI nor debits AI credits.
 *
 * Every identity, patient, image and brand here is synthetic (DECISIONS.md
 * PRV-01); no production database is ever touched.
 */
class ClinicRouteJourneyTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    /** A real, decodable 1×1 PNG (no GD dependency). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const BASE = '/api/v1/patient';

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
    private function activateModules(Tenant $tenant, array $modules = ['patients', 'appointments', 'lba']): void
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

    private function imageUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, (string) base64_decode(self::PNG));

        return new UploadedFile($path, 'scan.png', 'image/png', null, true);
    }

    // ==================== 1. Manual journey through the normal routes

    public function test_a_manual_case_reaches_a_released_patient_report_through_the_normal_routes(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Journey']);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);

        // 1. The owner registers an examination location from the TailAdmin surface.
        $this->actingAs($owner)->post('/clinic/workspace/locations', [
            'name' => 'Cabang Utama Journey',
            'code' => 'JRN-'.Str::upper(Str::random(4)),
            'status' => TenantBranch::STATUS_ACTIVE,
            'location_type' => TenantBranch::LOCATION_PERMANENT,
            'city' => 'Jakarta',
        ])->assertRedirect(route('clinic.tailadmin.locations'));

        $this->tenantDatabases()->configure($tenant->id);
        $branch = TenantBranch::query()->where('name', 'Cabang Utama Journey')->sole();
        $branchId = (string) $branch->getKey();

        // The operator and reviewer may work in that branch.
        $this->assignStaff($owner, $operator, $branchId);
        $this->assignStaff($owner, $reviewer, $branchId);

        // 2. A patient is registered, then an appointment is booked, both by route.
        $this->actingAs($owner)->post('/clinic/workspace/patients', [
            'tenant_id' => $tenant->id,
            'full_name' => 'Pasien Journey',
            'registered_branch_id' => $branchId,
            'status' => Patient::STATUS_ACTIVE,
        ])->assertRedirect('/clinic/workspace/patients');

        $this->tenantDatabases()->configure($tenant->id);
        $patient = Patient::query()->where('full_name', 'Pasien Journey')->sole();

        $starts = now()->addDay()->setTime(9, 0);
        $this->actingAs($owner)->post('/clinic/workspace/appointments', [
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->getKey(),
            'branch_id' => $branchId,
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $starts->copy()->addHour()->format('Y-m-d\TH:i'),
        ])->assertRedirect('/clinic/workspace/appointments');

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(1, Appointment::query()->where('patient_id', $patient->getKey())->count());

        // 3. The Manual LBA examination is opened through the intake form.
        $this->actingAs($owner)->post('/clinic/workspace/examinations', [
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->getKey(),
            'branch_id' => $branchId,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ])->assertRedirect('/clinic/workspace/examinations');

        $this->tenantDatabases()->configure($tenant->id);
        $examination = Examination::query()->where('patient_id', $patient->getKey())->sole();

        // 4. The case detail offers the connector to the assessment surface that
        //    was previously reachable only by typing the URL.
        $this->actingAs($operator)
            ->get(route('clinic.tailadmin.examinations.show', $examination))
            ->assertOk()
            ->assertSee(route('clinic.tailadmin.assessments.show', $examination))
            ->assertSee(__('lba.assessment.open'));

        // 5. A private still is uploaded (consent is recorded on the case).
        $this->actingAs($operator)
            ->post(route('clinic.tailadmin.examinations.images.store', $examination), [
                'image' => $this->imageUpload(),
            ])->assertRedirect(route('clinic.tailadmin.examinations.show', $examination));

        $this->tenantDatabases()->configure($tenant->id);
        $examination->refresh();
        $this->assertSame(Examination::STATUS_UPLOADED, $examination->status);
        $image = $examination->images()->sole();
        Storage::disk('lba')->assertExists($image->object_key);

        // 6. The operator records the assessment from the connected surface.
        $this->actingAs($operator)
            ->get(route('clinic.tailadmin.assessments.show', $examination))
            ->assertOk();

        $this->actingAs($operator)
            ->post(route('clinic.tailadmin.assessments.category', $examination), [
                'indicator_key' => 'asthma_paru_tbc',
                'category' => AssessmentItem::CATEGORY_YELLOW,
            ])->assertRedirect();

        $brand = $this->linkBrand($tenant);
        $this->actingAs($operator)
            ->post(route('clinic.tailadmin.assessments.brand', $examination), [
                'brand_id' => $brand->getKey(),
            ])->assertRedirect();

        $this->actingAs($operator)
            ->post(route('clinic.tailadmin.assessments.submit', $examination))
            ->assertRedirect(route('clinic.tailadmin.assessments.show', $examination));

        $this->tenantDatabases()->configure($tenant->id);
        $assessment = Assessment::query()->where('examination_id', $examination->getKey())->sole();
        $this->assertSame(Assessment::STATUS_SUBMITTED_FOR_REVIEW, $assessment->status);

        // 7. A DIFFERENT identity reviews and approves; the reviewer inbox is
        //    reachable and the operator may not decide.
        $this->actingAs($operator)
            ->get(route('clinic.tailadmin.reviews.index'))
            ->assertForbidden();

        $this->actingAs($reviewer)
            ->get(route('clinic.tailadmin.reviews.index'))
            ->assertOk()
            ->assertSee('Pasien Journey');

        $this->actingAs($reviewer)
            ->post(route('clinic.tailadmin.reviews.decide', $assessment), ['decision' => 'approve'])
            ->assertRedirect(route('clinic.tailadmin.reviews.show', $assessment));

        $this->tenantDatabases()->configure($tenant->id);
        $assessment->refresh();
        $this->assertSame(Assessment::STATUS_REVIEWED, $assessment->status);
        $version = $assessment->reportVersions()->sole();
        $this->assertInstanceOf(ReportVersion::class, $version);
        $this->assertFalse($version->isReleased());

        // 8. The reviewer explicitly releases the immutable report to the patient.
        $this->actingAs($reviewer)
            ->post(route('clinic.tailadmin.reviews.release', [$assessment, $version->getKey()]))
            ->assertRedirect(route('clinic.tailadmin.reviews.show', $assessment));

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertTrue($version->refresh()->isReleased());

        // 9. The patient opens their OWN released report through the patient API.
        $patientUser = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($patientUser, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        $this->tenantDatabases()->configure($tenant->id);
        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $owner);
        app(PatientAccountLinker::class)->redeemInvitation($patientUser, $code);

        $token = app(PatientApiTokenIssuer::class)
            ->issue($patientUser, (string) $tenant->getKey(), 'journey')['plain'];

        $list = $this->withToken($token)->getJson(self::BASE.'/reports')->assertOk();
        $this->assertSame([(string) $version->getKey()], array_column($list->json('data'), 'id'));

        $this->withToken($token)
            ->get(self::BASE.'/reports/'.$version->getKey().'/download')
            ->assertOk();
    }

    // ==================== 2. AI + Hybrid variants; Manual never bills

    public function test_ai_and_hybrid_run_the_mock_adapter_while_manual_never_debits_ai_credits(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Varian']);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->tenantDatabases()->configure($tenant->id);
        $branch = $this->createBranch($tenant);
        $branchId = (string) $branch->getKey();

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Pasien Varian',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        // AI and Hybrid both run the synthetic mock adapter from the normal route.
        foreach ([Examination::MODE_AI, Examination::MODE_HYBRID] as $mode) {
            $this->actingAs($owner)->post('/clinic/workspace/examinations', [
                'tenant_id' => $tenant->id,
                'patient_id' => $patient->getKey(),
                'branch_id' => $branchId,
                'mode' => $mode,
                'record_consent' => true,
            ])->assertRedirect('/clinic/workspace/examinations');

            $this->tenantDatabases()->configure($tenant->id);
            $examination = Examination::query()->where('mode', $mode)->sole();

            $this->actingAs($owner)
                ->post(route('clinic.tailadmin.examinations.images.store', $examination), [
                    'image' => $this->imageUpload(),
                ])->assertRedirect(route('clinic.tailadmin.examinations.show', $examination));

            $this->tenantDatabases()->configure($tenant->id);
            $image = $examination->refresh()->images()->sole();

            $this->actingAs($owner)
                ->post(route('clinic.tailadmin.examinations.analysis.start', [$examination, $image->getKey()]))
                ->assertRedirect(route('clinic.tailadmin.examinations.show', $examination));

            $this->tenantDatabases()->configure($tenant->id);
            $job = AnalysisJob::query()->where('examination_id', $examination->getKey())->sole();
            $this->assertSame(AnalysisJob::STATUS_COMPLETED, $job->status);
            $this->assertSame('mock', $job->provider);

            // The synthetic adapter is non-billable: no credit is ever debited.
            $this->assertFalse($job->isBillable());
            $this->assertSame(0, $job->creditUnits());
            $this->assertSame(2, Detection::query()->where('examination_id', $examination->getKey())->count());
        }

        // A Manual examination is never AI-capable: the action is absent from the
        // case detail and a forced call writes nothing.
        $this->actingAs($owner)->post('/clinic/workspace/examinations', [
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->getKey(),
            'branch_id' => $branchId,
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ])->assertRedirect('/clinic/workspace/examinations');

        $this->tenantDatabases()->configure($tenant->id);
        $manual = Examination::query()->where('mode', Examination::MODE_MANUAL)->sole();

        $this->actingAs($owner)
            ->post(route('clinic.tailadmin.examinations.images.store', $manual), [
                'image' => $this->imageUpload(),
            ])->assertRedirect(route('clinic.tailadmin.examinations.show', $manual));

        $this->tenantDatabases()->configure($tenant->id);
        $manualImage = $manual->refresh()->images()->sole();

        $this->actingAs($owner)
            ->get(route('clinic.tailadmin.examinations.show', $manual))
            ->assertOk()
            ->assertSee(__('lba.exams.ai.manual_note'))
            ->assertDontSee(route('clinic.tailadmin.examinations.analysis.start', [$manual, $manualImage->getKey()]));

        $this->actingAs($owner)
            ->post(route('clinic.tailadmin.examinations.analysis.start', [$manual, $manualImage->getKey()]))
            ->assertForbidden();

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(0, AnalysisJob::query()->where('examination_id', $manual->getKey())->count());
        $this->assertSame(0, Detection::query()->where('examination_id', $manual->getKey())->count());
    }
}
