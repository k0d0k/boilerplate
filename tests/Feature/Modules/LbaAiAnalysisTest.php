<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Jobs\Middleware\TenantDatabaseContext;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\LBA\AI\Contracts\VisionAnalyzer;
use Modules\LBA\AI\Dto\VisionRequest;
use Modules\LBA\AI\Dto\VisionResult;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\AI\Jobs\AnalyzeExaminationImage;
use Modules\LBA\AI\Services\AiAnalysisService;
use Modules\LBA\Filament\Pages\MicroscopeWorkspace;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Annotation;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\AnnotationRecorder;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\LBA\Services\MicroscopyImageStore;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 5 — AI vision gateway + asynchronous analysis (focused essentials).
 *
 * Only the nine required behaviours plus ONE end-to-end path are covered. Every
 * identity, patient and image here is synthetic; the mock adapter returns
 * clearly-marked synthetic output and never a diagnosis.
 */
class LbaAiAnalysisTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'lba';

    /** A real, decodable 1×1 PNG (no GD dependency). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Storage::fake('lba');
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeExamination(Tenant $tenant, User $owner, array $overrides = []): Examination
    {
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic AI Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_AI,
            'record_consent' => true,
        ], $overrides), $owner);
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, (string) base64_decode(self::PNG));

        return new UploadedFile($path, 'sample.png', 'image/png', null, true);
    }

    private function storeImage(Examination $examination, User $actor): ExaminationImage
    {
        return app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $actor);
    }

    private function service(): AiAnalysisService
    {
        return app(AiAnalysisService::class);
    }

    // ------------------------------------- 1. Authorized AI job creation

    public function test_an_authorized_operator_can_create_an_idempotent_ai_job(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $this->actingAs($owner);

        // The server-side gate allows it for this authorized identity.
        $this->assertTrue(ExaminationResource::canStartAiAnalysis($examination));

        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->assertTrue(Str::isUlid((string) $job->getKey()));
        $this->assertSame(AnalysisJob::STATUS_QUEUED, $job->status);
        $this->assertSame('mock', $job->provider);
        $this->assertTrue(Str::isUlid((string) $job->request_ref));
        $this->assertNotEmpty($job->idempotency_key);

        // The background job was dispatched with the verified tenant context.
        Queue::assertPushed(
            AnalyzeExaminationImage::class,
            fn (AnalyzeExaminationImage $queued): bool => $queued->tenantId === (string) $tenant->getKey()
                && $queued->jobId === (string) $job->getKey(),
        );

        $this->assertDatabaseHas('lba_analysis_jobs', [
            'id' => $job->getKey(),
            'status' => AnalysisJob::STATUS_QUEUED,
            'provider' => 'mock',
        ], 'tenant');
    }

    // ------------------------------------ 2. Cross-tenant isolation

    public function test_tenant_a_jobs_and_results_are_never_visible_to_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $examination = $this->makeExamination($tenantA, $ownerA);
        $image = $this->storeImage($examination, $ownerA);
        $this->bindTenant($tenantA);

        // Sync queue: the mock adapter completes the job inline.
        $job = $this->service()->startAnalysis($examination, $image, $ownerA);

        $this->bindTenant($tenantA);
        $this->assertSame(1, AnalysisJob::query()->count());
        $this->assertSame(2, Detection::query()->count());

        $tenantB = Tenant::factory()->create();
        $ownerB = $this->member($tenantB, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);
        $this->bindTenant($tenantB);

        // The isolated database hides tenant A's rows entirely.
        $this->assertSame(0, AnalysisJob::query()->count());
        $this->assertSame(0, Detection::query()->count());

        // Tenant B can never resolve (and therefore never process) tenant A's job.
        $this->service()->processJob((string) $job->getKey());
        $this->assertSame(0, Detection::query()->count());
    }

    // ------------------------------------ 3. Revoked consent blocks analysis

    public function test_a_withdrawn_consent_blocks_ai_analysis(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        // Withdraw the explicit image-processing consent.
        PatientConsent::query()->whereKey($examination->consent_id)->firstOrFail()->withdraw();
        $this->assertFalse($examination->fresh()->hasGrantedImageProcessingConsent());

        try {
            $this->service()->startAnalysis($examination->fresh(), $image, $owner);
            $this->fail('A withdrawn consent must block AI analysis.');
        } catch (AiAnalysisException $exception) {
            $this->assertSame('consent_required', $exception->errorCode());
        }

        $this->assertSame(0, AnalysisJob::query()->count());
    }

    // ------------------------------------ 4. Invalid gateway response rejected

    public function test_an_invalid_gateway_response_is_rejected_and_persists_nothing(): void
    {
        config()->set('lba.ai.provider', 'fastapi');
        config()->set('lba.ai.gateway.url', 'https://gateway.invalid.example');
        config()->set('lba.ai.gateway.key_id', 'test-key');
        config()->set('lba.ai.gateway.secret', 'test-secret');
        config()->set('lba.ai.gateway.allowed_hosts', ['gateway.invalid.example']);

        // A syntactically valid HTTP 200 whose payload violates the schema
        // (no detections list).
        Http::fake([
            '*' => Http::response([
                'protocol_version' => '1',
                'provider' => 'fastapi',
                'model' => ['name' => 'x', 'version' => 'y'],
                'synthetic' => false,
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $job->refresh();

        $this->assertSame(AnalysisJob::STATUS_FAILED, $job->status);
        $this->assertSame('invalid_response', $job->error_code);

        // No partial result is ever persisted.
        $this->assertSame(0, Detection::query()->count());
    }

    // ------------------------------------ 5. Duplicate job, no duplicate results

    public function test_a_duplicate_request_never_creates_a_second_job_or_detections(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $first = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $second = $this->service()->startAnalysis($examination, $image, $owner);

        // Idempotent: the same provider/protocol/exam/image resolves to ONE job.
        $this->assertSame((string) $first->getKey(), (string) $second->getKey());

        $this->bindTenant($tenant);
        $this->assertSame(1, AnalysisJob::query()->count());
        $this->assertSame(2, Detection::query()->count());

        // Re-completing the same job is a no-op — no duplicate detections.
        $job = AnalysisJob::query()->whereKey($first->getKey())->firstOrFail();
        $result = app(VisionAnalyzer::class)->analyze(new VisionRequest(
            jobReference: (string) $job->request_ref,
            imageReference: (string) $image->getKey(),
            contents: (string) Storage::disk('lba')->get((string) $image->object_key),
            checksum: (string) $image->checksum,
            mimeType: (string) $image->mime_type,
            protocolVersion: (string) config('lba.ai.protocol_version', '1'),
        ));

        $this->service()->completeAnalysis($job, $result);

        $this->bindTenant($tenant);
        $this->assertSame(2, Detection::query()->count());
    }

    // ------------------------------------ 6. Gateway offline, retryable failure

    public function test_an_unreachable_gateway_produces_a_retryable_failure_and_keeps_the_original(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        // An analyzer whose gateway is unreachable.
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

        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $job->refresh();

        $this->assertSame(AnalysisJob::STATUS_FAILED, $job->status);
        $this->assertSame('gateway_unavailable', $job->error_code);
        $this->assertTrue($job->isRetryable());
        $this->assertSame(0, Detection::query()->count());

        // The original image is untouched.
        $this->assertTrue(Storage::disk('lba')->exists((string) $image->object_key));

        // An authorized, attempt-limited retry re-queues it (and fails again
        // because the gateway is still offline).
        $retried = $this->service()->retryAnalysis($job, $owner);

        $this->bindTenant($tenant);
        $retried->refresh();

        $this->assertSame(2, $retried->attempts);
        $this->assertSame(AnalysisJob::STATUS_FAILED, $retried->status);
    }

    // ------------------------------------ 7. AI suggestions never auto-publish

    public function test_ai_suggestions_are_stored_as_unreviewed_and_never_auto_publish(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $statusBefore = $examination->fresh()->status;

        $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $fresh = Examination::query()->whereKey($examination->getKey())->firstOrFail();

        // Completing a job NEVER moves the clinical state.
        $this->assertSame($statusBefore, $fresh->status);
        $this->assertNotContains('approved', Examination::STATUSES);
        $this->assertNotContains('published', Examination::STATUSES);
        $this->assertFalse($fresh->canTransitionTo('approved'));

        // Suggestions live in their OWN table, never as human annotations.
        $this->assertSame(2, Detection::query()->count());
        $this->assertSame(0, Annotation::query()->count());

        $detection = Detection::query()->firstOrFail();
        $this->assertSame(Detection::PROVENANCE_AI, $detection->provenance);
        $this->assertTrue($detection->isSynthetic());
        $this->assertContains('not_a_diagnosis', (array) $detection->quality_flags);

        // The job presents as "results ready" without any approval.
        $job = AnalysisJob::query()->firstOrFail();
        $this->assertSame(AnalysisJob::STATUS_COMPLETED, $job->status);
        $this->assertSame('results_ready', $job->processingState());
    }

    // ------------------------------------ 8. Manual works with AI gateway offline

    public function test_manual_analysis_still_works_with_the_ai_gateway_offline(): void
    {
        config()->set('lba.ai.provider', 'fastapi');
        config()->set('lba.ai.gateway.url', 'https://gateway.invalid.example');
        config()->set('lba.ai.gateway.key_id', 'test-key');
        config()->set('lba.ai.gateway.secret', 'test-secret');
        config()->set('lba.ai.gateway.allowed_hosts', ['gateway.invalid.example']);
        Http::fake(fn () => throw new ConnectionException('gateway offline'));

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner, ['mode' => Examination::MODE_MANUAL]);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $annotation = app(AnnotationRecorder::class)->create($examination, $image, [
            'type' => Annotation::TYPE_RECTANGLE,
            'geometry' => ['x' => 0.10, 'y' => 0.20, 'width' => 0.30, 'height' => 0.40],
            'label' => 'Operator observation',
        ], $owner);

        $this->bindTenant($tenant);

        $this->assertSame(Annotation::PROVENANCE_HUMAN, $annotation->provenance);
        $this->assertNull($annotation->job_id);

        // Manual analysis is completely independent of the AI pipeline.
        $this->assertSame(0, AnalysisJob::query()->count());
        $this->assertSame(0, Detection::query()->count());
    }

    // ------------------------------------ 9. Tenant queue context released

    public function test_the_tenant_queue_context_is_released_after_processing(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $job = $this->service()->startAnalysis($examination, $image, $owner);

        // The queue middleware configured the tenant, ran the job, then released
        // it — so the next (possibly other-tenant) job can never inherit it.
        $this->assertNull(app(ResolvesTenantDatabase::class)->current());

        // Directly exercise the middleware contract as well.
        $middleware = new TenantDatabaseContext((string) $tenant->getKey());
        $seen = null;

        $middleware->handle($job, function () use (&$seen): void {
            $seen = app(ResolvesTenantDatabase::class)->current();
        });

        $this->assertSame((string) $tenant->getKey(), $seen);
        $this->assertNull(app(ResolvesTenantDatabase::class)->current());
    }

    // ------------------------------------ END-TO-END

    /**
     * One full path: Laravel queue -> mock gateway -> validated detection ->
     * tenant database -> Filament workspace display.
     */
    public function test_end_to_end_analysis_reaches_the_filament_workspace(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // A Hybrid examination: a human annotation AND an AI suggestion coexist.
        $examination = $this->makeExamination($tenant, $owner, ['mode' => Examination::MODE_HYBRID]);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        app(AnnotationRecorder::class)->create($examination, $image, [
            'type' => Annotation::TYPE_POINT,
            'geometry' => ['x' => 0.5, 'y' => 0.5],
        ], $owner);

        // The job runs inline through the sync queue and the mock gateway.
        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $job->refresh();

        $this->assertSame(AnalysisJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(2, Detection::query()->where('job_id', $job->getKey())->count());

        // The Filament microscope workspace displays the unreviewed suggestions
        // alongside the human annotation, without mixing them.
        $workspace = new MicroscopeWorkspace;
        $workspace->examinationId = (string) $examination->getKey();
        $workspace->selectedImageId = (string) $image->getKey();

        $this->assertSame(1, $workspace->annotations()->count());
        $this->assertSame(Annotation::PROVENANCE_HUMAN, $workspace->annotations()->first()->provenance);

        $suggestions = $workspace->detections();
        $this->assertSame(2, $suggestions->count());
        $this->assertSame(Detection::PROVENANCE_AI, $suggestions->first()->provenance);
        $this->assertArrayHasKey('synthetic_reference_marker', $workspace->aiLabelOptions());
        $this->assertSame('results_ready', $workspace->latestJob()->processingState());
    }
}
