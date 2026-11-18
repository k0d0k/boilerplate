<?php

namespace Tests\Feature\Modules;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\AiBilling\Models\TenantAiWalletLedgerEntry;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\LBA\Exceptions\AssessmentException;
use Modules\LBA\Filament\Pages\AssessmentForm;
use Modules\LBA\Filament\Pages\MicroscopeWorkspace;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentEvent;
use Modules\LBA\Models\AssessmentEvidenceLink;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\LBA\Services\MicroscopyImageStore;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 7A — digital LBA observation assessment (focused essentials).
 *
 * Covers ONLY the nine required behaviours: correct tenant + examination,
 * green/yellow/red/unassessed persistence, unassessed is never auto-green,
 * foreign-tenant/foreign-examination evidence is denied, manual mode works with
 * AI offline at zero credits, AI suggestions are never silently accepted, edits
 * and events are recorded, brand selection respects the tenant-brand link, and
 * the existing LBA Manual/AI/Hybrid UI keeps working. Every identity, patient
 * and image here is synthetic.
 */
class LbaAssessmentTest extends TestCase
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

        if (! isset($overrides['branch_id'])) {
            $overrides['branch_id'] = $this->createBranch($tenant)->getKey();
        }

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Assessment Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $overrides), $owner);
    }

    private function storeImage(Examination $examination, User $actor): ExaminationImage
    {
        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, (string) base64_decode(self::PNG));
        $upload = new UploadedFile($path, 'sample.png', 'image/png', null, true);

        return app(MicroscopyImageStore::class)->store($examination, $upload, $actor);
    }

    /**
     * An unreviewed AI suggestion (always AI-provenance, never a finding).
     */
    private function makeDetection(Examination $examination, User $actor): Detection
    {
        $image = $this->storeImage($examination, $actor);

        $job = AnalysisJob::query()->create([
            'examination_id' => $examination->getKey(),
            'image_id' => $image->getKey(),
            'request_ref' => 'req-'.Str::ulid(),
            'status' => AnalysisJob::STATUS_COMPLETED,
            'provider' => 'mock',
            'idempotency_key' => 'idem-'.Str::ulid(),
            'requested_by' => $actor->getKey(),
        ]);

        return Detection::query()->create([
            'examination_id' => $examination->getKey(),
            'image_id' => $image->getKey(),
            'job_id' => $job->getKey(),
            'provenance' => Detection::PROVENANCE_AI,
            'label' => 'synthetic_observation',
            'geometry' => ['x' => 0.1, 'y' => 0.2],
            'confidence' => 0.9,
            'quality_flags' => ['not_a_diagnosis'],
        ]);
    }

    private function linkBrand(Tenant $tenant, string $status = Brand::STATUS_ACTIVE): Brand
    {
        $brand = Brand::query()->create([
            'name' => 'Brand '.Str::upper(Str::random(4)),
            'slug' => 'brand-'.Str::lower(Str::random(6)),
            'status' => $status,
        ]);

        $brand->tenants()->attach($tenant->getKey(), ['is_primary' => true]);

        return $brand;
    }

    private function recorder(): AssessmentRecorder
    {
        return app(AssessmentRecorder::class);
    }

    // ============================================ 1. Correct tenant + examination

    public function test_an_assessment_belongs_to_its_tenant_examination_and_never_leaks_to_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $examination = $this->makeExamination($tenantA, $ownerA);
        $assessment = $this->recorder()->openFor($examination, $ownerA);

        // The assessment is stored in the ISOLATED tenant database, keyed to the
        // exact examination.
        $this->assertDatabaseHas('lba_assessments', [
            'id' => $assessment->getKey(),
            'examination_id' => $examination->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'status' => Assessment::STATUS_DRAFT,
        ], 'tenant');

        $this->assertSame((string) $examination->getKey(), (string) $assessment->examination_id);

        // The central catalog database holds NO patient clinical assessment.
        $central = (string) config('tenancy.central_connection');
        $this->assertFalse(Schema::connection($central)->hasTable('lba_assessments'));

        // Reopening is idempotent: one assessment per examination.
        $this->assertSame(
            $assessment->getKey(),
            $this->recorder()->openFor($examination->fresh(), $ownerA)->getKey(),
        );

        // A second tenant cannot see tenant A's assessment.
        $tenantB = Tenant::factory()->create();
        $this->member($tenantB, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);
        $this->bindTenant($tenantB);

        $this->assertSame(0, Assessment::query()->count());
    }

    // ============================================ 2. Selections persist

    public function test_green_yellow_red_and_unassessed_selections_persist_as_one_explicit_value(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $assessment = $this->recorder()->openFor($examination, $owner);

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_GREEN, $owner);
        $this->recorder()->setCategory($assessment, 'asam_urat_gout', AssessmentItem::CATEGORY_YELLOW, $owner);
        $this->recorder()->setCategory($assessment, 'ambeien_wasir', AssessmentItem::CATEGORY_RED, $owner);

        $this->bindTenant($tenant);

        foreach ([
            'asthma_paru_tbc' => AssessmentItem::CATEGORY_GREEN,
            'asam_urat_gout' => AssessmentItem::CATEGORY_YELLOW,
            'ambeien_wasir' => AssessmentItem::CATEGORY_RED,
            'alergi_bateri_basofil' => AssessmentItem::CATEGORY_UNASSESSED,
        ] as $key => $category) {
            $this->assertDatabaseHas('lba_assessment_items', [
                'assessment_id' => $assessment->getKey(),
                'indicator_key' => $key,
                'category' => $category,
            ], 'tenant');
        }

        // A single explicit column stores the category — never three booleans.
        $columns = Schema::connection('tenant')->getColumnListing('lba_assessment_items');
        $this->assertContains('category', $columns);
        $this->assertNotContains('is_green', $columns);
        $this->assertNotContains('is_yellow', $columns);
        $this->assertNotContains('is_red', $columns);
    }

    // ============================================ 3. Unassessed is never auto-green

    public function test_a_freshly_opened_assessment_is_entirely_unassessed_and_never_green(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $assessment = $this->recorder()->openFor($examination, $owner);

        $this->bindTenant($tenant);

        $items = AssessmentItem::query()->where('assessment_id', $assessment->getKey())->get();

        $this->assertCount(30, $items);
        $this->assertSame(30, $items->where('category', AssessmentItem::CATEGORY_UNASSESSED)->count());
        $this->assertSame(0, $items->where('category', AssessmentItem::CATEGORY_GREEN)->count());
    }

    // ============================================ 4. Foreign evidence denied

    public function test_foreign_examination_and_foreign_tenant_evidence_are_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $assessment = $this->recorder()->openFor($examination, $owner);

        // Same tenant, DIFFERENT examination.
        $other = $this->makeExamination($tenant, $owner);
        $foreignImage = $this->storeImage($other, $owner);

        try {
            $this->recorder()->attachEvidence(
                $assessment,
                'asthma_paru_tbc',
                AssessmentEvidenceLink::TYPE_IMAGE,
                (string) $foreignImage->getKey(),
                $owner,
            );
            $this->fail('Evidence from another examination must be denied.');
        } catch (AssessmentException $exception) {
            $this->assertStringContainsString('does not belong', strtolower($exception->getMessage()));
        }

        // Own image IS accepted (control).
        $ownImage = $this->storeImage($examination, $owner);
        $this->recorder()->attachEvidence(
            $assessment,
            'asthma_paru_tbc',
            AssessmentEvidenceLink::TYPE_IMAGE,
            (string) $ownImage->getKey(),
            $owner,
        );

        // Foreign TENANT cannot attach its evidence to this assessment, and the
        // assessment is not reachable from the other tenant at all.
        $tenantB = Tenant::factory()->create();
        $ownerB = $this->member($tenantB, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);

        $examinationB = $this->makeExamination($tenantB, $ownerB);
        $imageB = $this->storeImage($examinationB, $ownerB);

        $this->bindTenant($tenant);

        try {
            $this->recorder()->attachEvidence(
                $assessment,
                'asthma_paru_tbc',
                AssessmentEvidenceLink::TYPE_IMAGE,
                (string) $imageB->getKey(),
                $owner,
            );
            $this->fail('Evidence from another tenant must be denied.');
        } catch (AssessmentException $exception) {
            $this->assertStringContainsString('does not belong', strtolower($exception->getMessage()));
        }

        $this->bindTenant($tenantB);
        $this->assertSame(0, Assessment::query()->count());
    }

    // ============================================ 5. Manual works with AI offline

    public function test_manual_mode_completes_with_the_ai_stack_offline_and_costs_zero_credits(): void
    {
        // AI is off by default.
        $this->assertFalse((bool) config('lba.ai.enabled'));

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner, ['mode' => Examination::MODE_MANUAL]);
        $assessment = $this->recorder()->openFor($examination, $owner);

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_YELLOW, $owner);
        $this->recorder()->saveDraft($assessment, $owner);
        $this->recorder()->submitForReview($assessment, $owner);

        $this->bindTenant($tenant);

        $this->assertSame(
            Assessment::STATUS_SUBMITTED_FOR_REVIEW,
            Assessment::query()->whereKey($assessment->getKey())->firstOrFail()->status,
        );

        // No AI job, suggestion or wallet spend was required.
        $this->assertDatabaseCount('lba_analysis_jobs', 0, 'tenant');
        $this->assertDatabaseCount('lba_detections', 0, 'tenant');
        $this->assertSame(
            0,
            TenantAiWalletLedgerEntry::query()->where('tenant_id', $tenant->getKey())->count(),
        );
    }

    // ============================================ 6. AI suggestions never accepted

    public function test_ai_suggestions_are_shown_as_information_and_are_never_silently_recorded(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner, ['mode' => Examination::MODE_AI]);
        $detection = $this->makeDetection($examination, $owner);

        // Opening the assessment NEVER turns an AI suggestion into a category.
        $assessment = $this->recorder()->openFor($examination, $owner);
        $this->bindTenant($tenant);

        $this->assertSame(
            30,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('category', AssessmentItem::CATEGORY_UNASSESSED)
                ->count(),
        );

        // Linking the detection as evidence keeps AI provenance and leaves the
        // indicator unassessed — a suggestion is not a finding or a colour.
        $link = $this->recorder()->attachEvidence(
            $assessment,
            'asthma_paru_tbc',
            AssessmentEvidenceLink::TYPE_DETECTION,
            (string) $detection->getKey(),
            $owner,
        );

        $this->bindTenant($tenant);

        $this->assertSame(AssessmentEvidenceLink::PROVENANCE_AI, $link->provenance);
        $this->assertSame(
            AssessmentItem::CATEGORY_UNASSESSED,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
        );

        // Only an explicit human action records a category, and it is human.
        $item = $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_YELLOW, $owner);
        $this->assertSame(AssessmentItem::PROVENANCE_HUMAN, $item->provenance);
    }

    // ============================================ 7. Edits and events recorded

    public function test_assessment_edits_and_events_are_recorded_with_actor_and_time(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $assessment = $this->recorder()->openFor($examination, $owner);

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_RED, $owner);
        $this->recorder()->updateNotes($assessment, 'asthma_paru_tbc', 'Observed under review.', $owner);
        $this->recorder()->setProgramPhase($assessment, Assessment::PROGRAM_CLEANSING, $owner);
        $this->recorder()->saveDraft($assessment, $owner);
        $this->recorder()->submitForReview($assessment, $owner);

        $this->bindTenant($tenant);

        $actions = AssessmentEvent::query()
            ->where('assessment_id', $assessment->getKey())
            ->pluck('action')
            ->all();

        foreach ([
            AssessmentEvent::ACTION_CREATED,
            AssessmentEvent::ACTION_INDICATOR_SET,
            AssessmentEvent::ACTION_NOTES_UPDATED,
            AssessmentEvent::ACTION_PROGRAM_PHASE_SET,
            AssessmentEvent::ACTION_DRAFT_SAVED,
            AssessmentEvent::ACTION_STATUS_CHANGED,
        ] as $expected) {
            $this->assertContains($expected, $actions);
        }

        // The status transition is captured with its from/to and the actor.
        $this->assertDatabaseHas('lba_assessment_events', [
            'assessment_id' => $assessment->getKey(),
            'action' => AssessmentEvent::ACTION_STATUS_CHANGED,
            'from_status' => Assessment::STATUS_DRAFT,
            'to_status' => Assessment::STATUS_SUBMITTED_FOR_REVIEW,
            'actor_id' => $owner->getKey(),
        ], 'tenant');

        $this->assertDatabaseHas('lba_assessment_items', [
            'assessment_id' => $assessment->getKey(),
            'indicator_key' => 'asthma_paru_tbc',
            'notes' => 'Observed under review.',
            'assessor_id' => $owner->getKey(),
        ], 'tenant');

        // A submitted assessment is locked and can never be overwritten.
        try {
            $this->recorder()->setCategory($assessment->fresh(), 'asam_urat_gout', AssessmentItem::CATEGORY_GREEN, $owner);
            $this->fail('A submitted assessment must be locked against edits.');
        } catch (AssessmentException $exception) {
            $this->assertStringContainsString('locked', strtolower($exception->getMessage()));
        }
    }

    // ============================================ 8. Brand relationship

    public function test_brand_selection_respects_the_verified_tenant_brand_relationship(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $assessment = $this->recorder()->openFor($examination, $owner);

        // An active brand legitimately linked to the tenant is accepted and
        // snapshotted.
        $brand = $this->linkBrand($tenant);
        $this->recorder()->selectBrand($assessment, (string) $tenant->getKey(), (string) $brand->getKey(), $owner);

        $this->bindTenant($tenant);
        $this->assertDatabaseHas('lba_assessments', [
            'id' => $assessment->getKey(),
            'brand_id' => $brand->getKey(),
        ], 'tenant');

        $this->assertSame($brand->name, $assessment->fresh()->brand_snapshot['name']);

        // An active brand NOT linked to the tenant is refused.
        $unlinked = Brand::query()->create([
            'name' => 'Unlinked Brand',
            'slug' => 'unlinked-'.Str::lower(Str::random(6)),
            'status' => Brand::STATUS_ACTIVE,
        ]);

        $this->expectBrandRefusal($assessment, $tenant, (string) $unlinked->getKey());

        // A suspended brand linked to the tenant is refused too.
        $suspended = $this->linkBrand($tenant, Brand::STATUS_SUSPENDED);
        $this->expectBrandRefusal($assessment, $tenant, (string) $suspended->getKey());
    }

    private function expectBrandRefusal(Assessment $assessment, Tenant $tenant, string $brandId): void
    {
        try {
            $this->recorder()->selectBrand($assessment->fresh(), (string) $tenant->getKey(), $brandId, null);
            $this->fail('A brand not active and linked to the tenant must be refused.');
        } catch (AssessmentException $exception) {
            $this->assertStringContainsString('brand', strtolower($exception->getMessage()));
        }
    }

    // ============================================ 9. Existing UI still works

    public function test_the_existing_lba_workspace_and_the_new_assessment_page_both_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $this->storeImage($examination, $owner);

        $this->actingAs($owner);
        $this->bindTenant($tenant);

        // Both pages mount on the Livewire surface (the full-page HTTP layout is
        // not exercised because the sandbox has no built frontend bundle).
        Livewire::test(MicroscopeWorkspace::class, ['examination' => (string) $examination->getKey()])
            ->assertOk();

        Livewire::test(AssessmentForm::class, ['examination' => (string) $examination->getKey()])
            ->assertOk();

        $this->bindTenant($tenant);

        $assessment = Assessment::query()->where('examination_id', $examination->getKey())->firstOrFail();

        // The page's real actions drive the same common assessment model.
        $page = new AssessmentForm;
        $page->examinationId = (string) $examination->getKey();
        $page->assessmentId = (string) $assessment->getKey();
        $page->setCategory('asthma_paru_tbc', AssessmentItem::CATEGORY_YELLOW);

        $this->bindTenant($tenant);

        $this->assertSame(
            AssessmentItem::CATEGORY_YELLOW,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
        );

        // Submitting for review from the page locks it without approving a report.
        $page->submitForReview();

        $this->bindTenant($tenant);

        $fresh = Assessment::query()->whereKey($assessment->getKey())->firstOrFail();

        $this->assertSame(Assessment::STATUS_SUBMITTED_FOR_REVIEW, $fresh->status);
        $this->assertNull($fresh->reviewed_at);
        $this->assertNotContains('approved', Assessment::STATUSES);
        $this->assertNotContains('published', Assessment::STATUSES);
    }
}
