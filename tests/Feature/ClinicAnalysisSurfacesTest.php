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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\LBA\AI\Contracts\VisionAnalyzer;
use Modules\LBA\AI\Dto\VisionRequest;
use Modules\LBA\AI\Dto\VisionResult;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\LBA\Services\MicroscopyImageStore;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 5/11 — AI vision analysis driven from the Clinic TailAdmin case detail.
 *
 * The AI pipeline used to be reachable only through the Filament
 * `MicroscopeWorkspace` escape hatch. These tests pin the TailAdmin
 * counterpart: starting and retrying an analysis must go through the normal
 * routes, re-derive every authorization fact on the server, and — critically —
 * a Manual examination must never start AI nor debit AI credits (the synthetic
 * mock adapter is non-billable). Every identity, patient and image is synthetic
 * (DECISIONS.md PRV-01).
 */
class ClinicAnalysisSurfacesTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

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

    private function makeExamination(Tenant $tenant, User $owner, string $branchId, string $mode = Examination::MODE_AI): Examination
    {
        $this->tenantDatabases()->configure($tenant->id);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic AI Surface Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'mode' => $mode,
            'record_consent' => true,
            'branch_id' => $branchId,
        ], $owner);
    }

    private function storeImage(Examination $examination, User $actor): ExaminationImage
    {
        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, (string) base64_decode(self::PNG));
        $upload = new UploadedFile($path, 'sample.png', 'image/png', null, true);

        return app(MicroscopyImageStore::class)->store($examination, $upload, $actor);
    }

    // ============================== 1. Start from the TailAdmin case detail

    public function test_an_ai_analysis_runs_from_the_tailadmin_case_detail(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        $branch = $this->createBranch($tenant);
        $examination = $this->makeExamination($tenant, $owner, $branch->id);
        $image = $this->storeImage($examination, $owner);
        $this->tenantDatabases()->configure($tenant->id);

        $this->actingAs($owner);

        // The server-side gate allows it for this authorized identity.
        $this->assertTrue(ExaminationResource::canStartAiAnalysis($examination));

        // The action is offered on the case detail, never as a Filament link.
        $this->get(route('clinic.tailadmin.examinations.show', $examination))
            ->assertOk()
            ->assertSee(route('clinic.tailadmin.examinations.analysis.start', [$examination, $image]));

        // Starting the analysis goes through the normal TailAdmin route; the
        // sync queue runs the mock adapter inline to completion.
        $this->post(route('clinic.tailadmin.examinations.analysis.start', [$examination, $image]))
            ->assertRedirect(route('clinic.tailadmin.examinations.show', $examination));

        $this->tenantDatabases()->configure($tenant->id);
        $job = AnalysisJob::query()->sole();
        $this->assertSame(AnalysisJob::STATUS_COMPLETED, $job->status);
        $this->assertSame('mock', $job->provider);

        // Synthetic AI inference is non-billable: no AI credits are debited.
        $this->assertFalse($job->isBillable());
        $this->assertSame(0, $job->creditUnits());

        // The suggestions are stored as unreviewed AI detections, never as human
        // annotations, and the case is still not approved.
        $this->assertSame(2, Detection::query()->count());
        $this->assertSame(Detection::PROVENANCE_AI, Detection::query()->firstOrFail()->provenance);

        // The case detail renders the completed job and its suggestions.
        $this->actingAs($owner)
            ->get(route('clinic.tailadmin.examinations.show', $examination))
            ->assertOk()
            ->assertSee(__('lba.exams.ai.state_results_ready'))
            ->assertSee('synthetic_reference_marker')
            ->assertSee(__('lba.exams.ai.synthetic_tag'));
    }

    // ============================== 2. Permission gating

    public function test_the_ai_start_action_is_refused_without_the_ai_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $manager = $this->member($tenant, RoleKey::BRANCH_MANAGER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        $branch = $this->createBranch($tenant);
        // The branch manager may operate in the branch but holds NO exam.ai.start.
        $this->assignStaff($owner, $manager, $branch->id);

        $examination = $this->makeExamination($tenant, $owner, $branch->id);
        $image = $this->storeImage($examination, $owner);
        $this->tenantDatabases()->configure($tenant->id);

        $this->actingAs($manager)
            ->post(route('clinic.tailadmin.examinations.analysis.start', [$examination, $image]))
            ->assertForbidden();

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(0, AnalysisJob::query()->count());
    }

    // ============================== 3. Manual never runs AI

    public function test_a_manual_examination_never_starts_ai_nor_debits_credits(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        $branch = $this->createBranch($tenant);
        $examination = $this->makeExamination($tenant, $owner, $branch->id, Examination::MODE_MANUAL);
        $image = $this->storeImage($examination, $owner);
        $this->tenantDatabases()->configure($tenant->id);

        $this->actingAs($owner);

        // Manual mode is never AI-capable: the gate is closed even for an owner.
        $this->assertFalse(ExaminationResource::canStartAiAnalysis($examination));
        $this->assertFalse($examination->isAiCapable());

        // The action is not even offered, and the manual note is shown instead.
        $this->get(route('clinic.tailadmin.examinations.show', $examination))
            ->assertOk()
            ->assertSee(__('lba.exams.ai.manual_note'))
            ->assertDontSee(route('clinic.tailadmin.examinations.analysis.start', [$examination, $image]));

        // Forced through the route, it is refused and writes nothing.
        $this->post(route('clinic.tailadmin.examinations.analysis.start', [$examination, $image]))
            ->assertForbidden();

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(0, AnalysisJob::query()->count());
        $this->assertSame(0, Detection::query()->count());
    }

    // ============================== 4. Retry a failed job

    public function test_a_failed_ai_job_can_be_retried_from_the_tailadmin_route(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        // An analyzer whose gateway is unreachable so the job fails (retryable).
        $this->app->instance(VisionAnalyzer::class, new class implements VisionAnalyzer
        {
            public function provider(): string
            {
                return 'offline-stub';
            }

            public function analyze(VisionRequest $request): VisionResult
            {
                throw AiAnalysisException::gatewayUnavailable();
            }
        });

        $branch = $this->createBranch($tenant);
        $examination = $this->makeExamination($tenant, $owner, $branch->id);
        $image = $this->storeImage($examination, $owner);
        $this->tenantDatabases()->configure($tenant->id);

        $this->actingAs($owner)
            ->post(route('clinic.tailadmin.examinations.analysis.start', [$examination, $image]))
            ->assertRedirect(route('clinic.tailadmin.examinations.show', $examination));

        $this->tenantDatabases()->configure($tenant->id);
        $job = AnalysisJob::query()->sole();
        $this->assertSame(AnalysisJob::STATUS_FAILED, $job->status);
        $this->assertTrue($job->isRetryable());

        // The retry is an explicit, attempt-limited domain action.
        $this->actingAs($owner)
            ->post(route('clinic.tailadmin.examinations.analysis.retry', [$examination, $job]))
            ->assertRedirect(route('clinic.tailadmin.examinations.show', $examination));

        $this->tenantDatabases()->configure($tenant->id);
        $job->refresh();
        $this->assertSame(2, $job->attempts);
        $this->assertSame(AnalysisJob::STATUS_FAILED, $job->status);
    }

    // ============================== 5. Cross-examination image fails closed

    public function test_an_ai_action_on_a_foreign_image_id_is_not_found(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activateModules($tenant);

        $branch = $this->createBranch($tenant);
        $examination = $this->makeExamination($tenant, $owner, $branch->id);
        $other = $this->makeExamination($tenant, $owner, $branch->id);
        $foreignImage = $this->storeImage($other, $owner);
        $this->tenantDatabases()->configure($tenant->id);

        // The image is resolved through THIS examination's own relation, so an
        // image that belongs to another case never resolves (404, never widened).
        $this->actingAs($owner)
            ->post(route('clinic.tailadmin.examinations.analysis.start', [$examination, $foreignImage]))
            ->assertNotFound();

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(0, AnalysisJob::query()->count());
    }
}
