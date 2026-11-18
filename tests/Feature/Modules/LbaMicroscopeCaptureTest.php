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
use Livewire\Livewire;
use Modules\LBA\Filament\Pages\MicroscopeWorkspace;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS6.A — live microscope browser capture.
 *
 * A still captured in the browser is handed to the component by Livewire's
 * authenticated, CSRF-protected temporary upload and then runs through the SAME
 * validated, private, immutable intake path as a manual import. These tests
 * prove the capture action never bypasses the intake gates (consent,
 * editability, permission, module access), never writes to a public disk, and
 * never lets one clinic's frame land in another clinic's database.
 *
 * Filament 5 page components cannot be driven through `Livewire::test()->call()`
 * in this application (the follow-up re-render POST fails snapshot validation —
 * see {@see DrivesPrincipalPanel}). The page mount is therefore
 * asserted on the Livewire surface, and the capture action is invoked on the
 * real component instance, exactly as the repository's other LBA tests do.
 *
 * Only synthetic identities, patients and 1×1 images are used. The
 * HTTPS-secure-context / device-permission behaviour is client-side JavaScript
 * and is exercised during browser QA on staging, not here.
 */
class LbaMicroscopeCaptureTest extends TestCase
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
            'full_name' => 'Synthetic Capture Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $overrides), $owner);
    }

    /**
     * A real, decodable image accepted by the private intake store.
     */
    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, (string) base64_decode(self::PNG));

        return new UploadedFile($path, 'capture.png', 'image/png', null, true);
    }

    /**
     * Build the real component bound to an examination, acting as the operator
     * inside the tenant, exactly as the mounted page would be.
     */
    private function workspace(Tenant $tenant, User $actor, Examination $examination): MicroscopeWorkspace
    {
        $this->actingAs($actor);
        $this->bindTenant($tenant);

        $workspace = new MicroscopeWorkspace;
        $workspace->examinationId = (string) $examination->getKey();

        return $workspace;
    }

    private function imageCount(Tenant $tenant, Examination $examination): int
    {
        $this->bindTenant($tenant);

        return ExaminationImage::query()->where('examination_id', $examination->getKey())->count();
    }

    public function test_an_authorised_operator_stores_a_captured_still_privately(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);

        $this->actingAs($owner);
        $this->bindTenant($tenant);

        // The page mounts and renders for an authorised operator.
        Livewire::test(MicroscopeWorkspace::class, ['examination' => (string) $examination->getKey()])
            ->assertOk();

        $workspace = $this->workspace($tenant, $owner, $examination);

        $this->assertTrue($workspace->canCapture());
        $this->assertSame(
            (int) config('lba.images.max_kilobytes'),
            $workspace->captureUploadLimitKb(),
        );

        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage();

        // The temp upload is cleared and the new image is selected, ready for
        // manual annotation of the captured frame.
        $this->assertNull($workspace->capturedImage);

        $this->bindTenant($tenant);

        $image = ExaminationImage::query()->where('examination_id', $examination->getKey())->sole();

        $this->assertSame('image/png', $image->mime_type);
        $this->assertNotEmpty($image->checksum);
        $this->assertSame('lba', $image->disk);
        $this->assertStringStartsWith('examinations/', (string) $image->object_key);
        $this->assertSame(1, $image->width);
        $this->assertSame((string) $image->getKey(), $workspace->selectedImageId);

        // The draft examination advanced to `uploaded` through the same private
        // intake path as a manual import — never through a public write.
        $this->assertSame(
            Examination::STATUS_UPLOADED,
            Examination::query()->whereKey($examination->getKey())->firstOrFail()->status,
        );
    }

    public function test_capture_is_refused_without_image_processing_consent(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner, ['record_consent' => false]);

        $workspace = $this->workspace($tenant, $owner, $examination);

        $this->assertFalse($workspace->canCapture());

        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage();

        $this->assertNull($workspace->capturedImage);
        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_capture_is_refused_when_the_examination_is_not_editable(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);

        $this->bindTenant($tenant);
        $examination->forceFill(['status' => Examination::STATUS_REVIEW_REQUIRED])->save();

        $workspace = $this->workspace($tenant, $owner, $examination);

        $this->assertFalse($workspace->canCapture());

        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage();

        $this->assertNull($workspace->capturedImage);
        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_capture_is_refused_without_the_exam_update_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);

        $workspace = $this->workspace($tenant, $receptionist, $examination);

        $this->assertFalse($workspace->canCapture());

        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage();

        $this->assertNull($workspace->capturedImage);
        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_capture_is_refused_for_a_suspended_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $suspended = $this->member($tenant, RoleKey::LBA_OPERATOR, TenantMembership::STATUS_SUSPENDED);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);

        $workspace = $this->workspace($tenant, $suspended, $examination);

        $this->assertFalse($workspace->canCapture());

        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage();

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_a_captured_still_from_one_clinic_never_lands_in_another(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);
        $examinationA = $this->makeExamination($tenantA, $ownerA);

        $tenantB = Tenant::factory()->create();
        $ownerB = $this->member($tenantB, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);
        $examinationB = $this->makeExamination($tenantB, $ownerB);

        $workspaceA = $this->workspace($tenantA, $ownerA, $examinationA);
        $workspaceA->capturedImage = $this->pngUpload();
        $workspaceA->storeCapturedImage();

        $this->assertSame(1, $this->imageCount($tenantA, $examinationA));
        $this->assertSame(0, $this->imageCount($tenantB, $examinationB));
    }

    public function test_capture_is_refused_when_the_module_is_not_entitled(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);

        // RegisteredModule/entitlement deliberately NOT created for this tenant.
        $examination = $this->makeExamination($tenant, $owner);

        $workspace = $this->workspace($tenant, $owner, $examination);
        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage();

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }
}
