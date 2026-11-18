<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\TenantMembership;
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
use Livewire\Livewire;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Pages\MicroscopeWorkspace;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Annotation;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\AnnotationRecorder;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\LBA\Services\ExaminationWorkflow;
use Modules\LBA\Services\MicroscopyImageStore;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 4 — LBA module (focused essentials).
 *
 * Covers only the critical behaviour: draft examination creation with the
 * patient relationship, tenant/branch isolation, the explicit image-processing
 * consent gate, private image storage + authenticated streaming, manual
 * annotations with no AI job, the manual workflow to review_required, the
 * absence of any auto-approval path, and coordinate stability across image
 * changes. Only synthetic identities, patients and 1×1 images are used.
 */
class LbaModuleTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    private function member(Tenant $tenant, string $roleKey, string $status = TenantMembership::STATUS_ACTIVE): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey], $status);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function activate(Tenant $tenant): void
    {
        // The global registry entry is shared across tenants; only create it once.
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
            'full_name' => 'Synthetic LBA Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $overrides), $owner);
    }

    private function upload(string $contents, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function pngUpload(): UploadedFile
    {
        return $this->upload((string) base64_decode(self::PNG), 'sample.png', 'image/png');
    }

    // ------------------------------------------------ 1. Creation + relation

    public function test_an_examination_is_created_as_a_draft_with_its_patient_and_consent(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);

        $this->assertTrue(Str::isUlid((string) $examination->getKey()));
        $this->assertSame(Examination::STATUS_DRAFT, $examination->status);
        $this->assertSame(Examination::MODE_MANUAL, $examination->mode);
        $this->assertSame($owner->getKey(), $examination->created_by);

        // Patient relationship resolves inside the verified tenant database.
        $this->bindTenant($tenant);
        $this->assertInstanceOf(Patient::class, $examination->patient);
        $this->assertTrue($examination->hasGrantedImageProcessingConsent());

        // An explicit creation event is recorded.
        $this->assertDatabaseHas('lba_examination_events', [
            'examination_id' => $examination->getKey(),
            'from_status' => null,
            'to_status' => Examination::STATUS_DRAFT,
        ], 'tenant');
    }

    // ------------------------------------------------- 2. Tenant isolation

    public function test_tenant_a_examinations_and_images_are_never_visible_to_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $examination = $this->makeExamination($tenantA, $ownerA);
        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $ownerA);

        $tenantB = Tenant::factory()->create();
        $ownerB = $this->member($tenantB, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenantB);
        // Tenant B is fully entitled too, so the only reason the image is
        // unreachable is the isolated database — not a feature-gate refusal.
        $this->activate($tenantB);
        $this->bindTenant($tenantB);

        // Switching to the verified tenant B connection releases tenant A's rows.
        $this->assertSame(0, Examination::query()->count());
        $this->assertSame(0, ExaminationImage::query()->count());

        // And tenant A's image cannot be streamed from tenant B's context.
        $this->actingAs($ownerB)
            ->get(route('lba.images.stream', ['image' => $image->getKey()]))
            ->assertNotFound();
    }

    // ------------------------------------------- 3. Unauthorized access denied

    public function test_a_role_without_exam_permission_cannot_see_examinations(): void
    {
        $tenant = Tenant::factory()->create();
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        $this->actingAs($receptionist);

        $this->assertFalse(ExaminationResource::canViewAny());
        $this->assertFalse(ExaminationResource::canCreate());
    }

    public function test_a_branch_restricted_identity_only_sees_its_own_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->bindTenant($tenant);
        $branchA = $this->createBranch($tenant, ['name' => 'Branch A']);
        $branchB = $this->createBranch($tenant, ['name' => 'Branch B']);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Branch Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        foreach ([$branchA, $branchB] as $branch) {
            app(ExaminationRegistrar::class)->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $branch->getKey(),
                'mode' => Examination::MODE_MANUAL,
                'record_consent' => true,
            ], $owner);
        }

        // The operator may operate only in branch A.
        $this->assignStaff($owner, $operator, (string) $branchA->getKey());

        $this->actingAs($operator);
        $this->bindTenant($tenant);

        $this->assertSame(
            [(string) $branchA->getKey()],
            ExaminationResource::getEloquentQuery()->pluck('branch_id')->all(),
        );
    }

    // ------------------------------------------------------- 4. Consent gate

    public function test_image_processing_requires_a_specific_image_processing_consent(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // No consent recorded at all.
        $without = $this->makeExamination($tenant, $owner, ['record_consent' => false]);
        $this->assertFalse($without->hasGrantedImageProcessingConsent());

        try {
            app(MicroscopyImageStore::class)->store($without, $this->pngUpload(), $owner);
            $this->fail('Image processing must be refused without a recorded consent.');
        } catch (ExaminationException $exception) {
            $this->assertStringContainsString('consent', strtolower($exception->getMessage()));
        }

        // A handling-time recorded consent authorises it.
        $with = $this->makeExamination($tenant, $owner, ['record_consent' => true]);
        $image = app(MicroscopyImageStore::class)->store($with, $this->pngUpload(), $owner);

        $this->assertInstanceOf(ExaminationImage::class, $image);

        // A consent that is later WITHDRAWN no longer authorises processing.
        $withdrawn = $this->makeExamination($tenant, $owner, ['record_consent' => true]);
        $this->assertTrue($withdrawn->hasGrantedImageProcessingConsent());

        $this->bindTenant($tenant);
        PatientConsent::query()->whereKey($withdrawn->consent_id)->firstOrFail()->withdraw();

        $this->assertFalse($withdrawn->fresh()->hasGrantedImageProcessingConsent());

        try {
            app(MicroscopyImageStore::class)->store($withdrawn, $this->pngUpload(), $owner);
            $this->fail('Withdrawn consent must prevent image processing.');
        } catch (ExaminationException $exception) {
            $this->assertStringContainsString('consent', strtolower($exception->getMessage()));
        }
    }

    // ---------------------------------------------------- 5. Invalid files

    public function test_non_image_and_unsafe_files_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);

        $unsafe = [
            $this->upload('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'vector.svg', 'image/svg+xml'),
            $this->upload('<?php echo "x"; ?>', 'script.png', 'image/png'),
        ];

        foreach ($unsafe as $file) {
            try {
                app(MicroscopyImageStore::class)->store($examination, $file, $owner);
                $this->fail('A non-image file must be rejected.');
            } catch (ExaminationException $exception) {
                $this->assertStringContainsString('rejected', strtolower($exception->getMessage()));
            }
        }
    }

    // --------------------------------------------- 6. Private storage + access

    public function test_originals_are_private_and_only_streamed_when_authenticated(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        // An operator must be assigned to a branch before it may read its
        // examinations; without this the branch-scope guard denies access.
        $this->assignStaff($owner, $operator, (string) $examination->branch_id);

        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        // Stored on the private disk, never on the public disk.
        $this->assertTrue(Storage::disk('lba')->exists((string) $image->object_key));
        $this->assertFalse(Storage::disk('public')->exists((string) $image->object_key));

        // The stream URL carries no storage path or original filename.
        $url = $image->streamUrl();
        $this->assertStringNotContainsString((string) $image->object_key, $url);
        $this->assertStringNotContainsString('sample.png', $url);

        // Guests are refused.
        $this->get($url)->assertRedirect();

        // An authorised identity streams it with no-store caching.
        $this->actingAs($operator)
            ->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ------------------------------------------- 7. Manual annotation + no AI

    public function test_a_manual_annotation_persists_with_no_ai_job(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        $annotation = app(AnnotationRecorder::class)->create($examination, $image, [
            'type' => Annotation::TYPE_RECTANGLE,
            'geometry' => ['x' => 0.10, 'y' => 0.20, 'width' => 0.30, 'height' => 0.40],
            'label' => 'Operator observation',
        ], $owner);

        $this->assertNull($annotation->job_id);
        $this->assertSame(Annotation::PROVENANCE_HUMAN, $annotation->provenance);
        $this->assertTrue($annotation->isManual());
        $this->assertDatabaseHas('lba_annotations', [
            'id' => $annotation->getKey(),
            'job_id' => null,
            'provenance' => 'human',
        ], 'tenant');
    }

    // --------------------------------------------- 8/9. Manual workflow, no auto-approval

    public function test_the_manual_workflow_reaches_review_required_and_never_auto_approves(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        app(AnnotationRecorder::class)->create($examination, $image, [
            'type' => Annotation::TYPE_POINT,
            'geometry' => ['x' => 0.5, 'y' => 0.5],
        ], $owner);

        app(ExaminationWorkflow::class)->transition($examination, Examination::STATUS_REVIEW_REQUIRED, $owner);

        $this->assertSame(Examination::STATUS_REVIEW_REQUIRED, $examination->fresh()->status);

        // No approval / publication state exists in Phase 4.
        $this->assertNotContains('approved', Examination::STATUSES);
        $this->assertNotContains('published', Examination::STATUSES);
        $this->assertFalse($examination->canTransitionTo('approved'));
        $this->assertFalse($examination->canTransitionTo('published'));

        // The workflow history is append-only and complete.
        $this->assertSame(4, $examination->events()->count());

        // A review-required examination is read-only for editing.
        $this->assertFalse($examination->fresh()->isEditable());
    }

    // ------------------------------------ 10. Image change / zoom / pan keeps coords

    public function test_changing_images_and_view_transforms_preserve_stored_coordinates(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        $geometry = ['x' => 0.25, 'y' => 0.35, 'width' => 0.20, 'height' => 0.15];
        $annotation = app(AnnotationRecorder::class)->create($examination, $image, [
            'type' => Annotation::TYPE_RECTANGLE,
            'geometry' => $geometry,
        ], $owner);

        // A second image does not touch the first image's annotation.
        app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        $this->bindTenant($tenant);
        $stored = Annotation::query()->whereKey($annotation->getKey())->firstOrFail();

        // Normalised against the original image (0..1), so it is independent of
        // the viewer's zoom, pan or screen size.
        $this->assertEqualsWithDelta(0.25, $stored->geometry['x'], 0.0001);
        $this->assertEqualsWithDelta(0.35, $stored->geometry['y'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $stored->geometry['width'], 0.0001);
        $this->assertEqualsWithDelta(0.15, $stored->geometry['height'], 0.0001);
    }

    // ----------------------------------------- 11. Microscope workspace smoke

    /**
     * A browser-class smoke test of the dark microscope workspace, driven through
     * its real Livewire component: it mounts for an authorised operator, accepts
     * a manual point annotation, and reaches review_required — all with the AI
     * server offline. (The sandbox has no built frontend bundle, so the full-page
     * HTTP layout is exercised via the component instead of a real browser.)
     */
    public function test_the_microscope_workspace_can_be_driven_manually_without_ai(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        $this->actingAs($owner);
        $this->bindTenant($tenant);

        // The page mounts and renders on the Livewire surface for an authorised
        // operator (the full-page HTTP layout is not exercised because the
        // sandbox has no built frontend bundle).
        Livewire::test(MicroscopeWorkspace::class, ['examination' => (string) $examination->getKey()])
            ->assertOk();

        // Its real manual actions run end-to-end with the AI server offline.
        $workspace = new MicroscopeWorkspace;
        $workspace->examinationId = (string) $examination->getKey();
        $workspace->selectedImageId = (string) $examination->images()->value('id');
        $workspace->setTool('point');
        $workspace->createAnnotation('point', ['x' => 0.4, 'y' => 0.6]);
        $workspace->submitForReview();

        $this->bindTenant($tenant);

        $this->assertSame(
            Examination::STATUS_REVIEW_REQUIRED,
            Examination::query()->whereKey($examination->getKey())->firstOrFail()->status,
        );

        $this->assertDatabaseHas('lba_annotations', [
            'examination_id' => $examination->getKey(),
            'job_id' => null,
            'provenance' => Annotation::PROVENANCE_HUMAN,
        ], 'tenant');
    }

    // ------------------------------------- 12. Tenant-connection write rollback

    /**
     * A tenant-owned clinical write transaction must run on the VERIFIED tenant
     * database. If it ran on the default (central) connection, a failure after
     * a tenant row was written would leave that row behind instead of rolling
     * it back.
     */
    public function test_a_tenant_write_transaction_rolls_back_on_the_verified_tenant_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Rollback Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        // Fail the examination INSERT *after* the consent rows have already been
        // written inside the same transaction.
        Examination::creating(function (): void {
            throw new \RuntimeException('forced failure');
        });

        try {
            app(ExaminationRegistrar::class)->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $branch->getKey(),
                'mode' => Examination::MODE_MANUAL,
                'record_consent' => true,
            ], $owner);

            $this->fail('The forced failure must abort the examination creation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced failure', $exception->getMessage());
        }

        $this->bindTenant($tenant);

        // Everything written in that transaction — including the consent recorded
        // before the failure — rolled back on the tenant connection. A central
        // transaction would have left the consent and event rows committed.
        $this->assertSame(0, Examination::query()->count());
        $this->assertDatabaseCount('patient_consents', 0, 'tenant');
        $this->assertDatabaseCount('lba_examination_events', 0, 'tenant');

        // The patient, committed by its own transaction, is untouched.
        $this->assertDatabaseHas('patients', ['id' => $patient->getKey()], 'tenant');
    }

    // -------------------------------------------- 13. Originals are retained

    public function test_original_images_are_retained_and_never_physically_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $image = app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        $annotation = app(AnnotationRecorder::class)->create($examination, $image, [
            'type' => Annotation::TYPE_POINT,
            'geometry' => ['x' => 0.5, 'y' => 0.5],
        ], $owner);

        try {
            app(MicroscopyImageStore::class)->delete($examination, $image);
            $this->fail('Physical deletion of an original image must be disabled.');
        } catch (ExaminationException $exception) {
            $this->assertStringContainsString('retain', strtolower($exception->getMessage()));
        }

        $this->bindTenant($tenant);

        // The original object, its row and the annotation referencing it survive.
        $this->assertTrue(Storage::disk('lba')->exists((string) $image->object_key));
        $this->assertDatabaseHas('lba_images', ['id' => $image->getKey()], 'tenant');
        $this->assertDatabaseHas('lba_annotations', ['id' => $annotation->getKey()], 'tenant');
    }
}
