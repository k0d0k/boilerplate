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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\LBA\Filament\Pages\MicroscopeWorkspace;
use Modules\LBA\Ingest\IngestCredentialIssuer;
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
 * WS6.C — per-image origin metadata & operational checks.
 *
 * Every immutable original records the ENGINEERING provenance of the still —
 * its source (manual import / browser capture / scoped agent import), the
 * capture instant, an instrument/device label and the integrity checksum — so
 * an operator can see where a frame came from WITHOUT any patient identity
 * being duplicated into the metadata (and therefore never leaked into logs).
 *
 * These tests prove the store records the correct, bounded, non-clinical
 * origin for each intake path and that unknown values can never invent a new
 * source. Manual LBA remains fully functional with no AI server involved.
 *
 * Only synthetic identities, patients and 1×1 images are used.
 */
class LbaImageOriginTest extends TestCase
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
            'full_name' => 'Synthetic Origin Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $overrides), $owner);
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, (string) base64_decode(self::PNG));

        return new UploadedFile($path, 'capture.png', 'image/png', null, true);
    }

    private function workspace(Tenant $tenant, User $actor, Examination $examination): MicroscopeWorkspace
    {
        $this->actingAs($actor);
        $this->bindTenant($tenant);

        $workspace = new MicroscopeWorkspace;
        $workspace->examinationId = (string) $examination->getKey();

        return $workspace;
    }

    private function soleImage(Tenant $tenant, Examination $examination): ExaminationImage
    {
        $this->bindTenant($tenant);

        return ExaminationImage::query()->where('examination_id', $examination->getKey())->sole();
    }

    public function test_a_manual_import_records_the_manual_source(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $this->bindTenant($tenant);

        app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        $image = $this->soleImage($tenant, $examination);

        $this->assertSame(ExaminationImage::SOURCE_MANUAL_IMPORT, $image->source);
        $this->assertNull($image->captured_at);
        $this->assertNull($image->instrument_label);
        $this->assertNotEmpty($image->checksum);
    }

    public function test_a_browser_capture_records_its_source_time_and_device(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $workspace = $this->workspace($tenant, $owner, $examination);
        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage('OptoEdu Scout Camera');

        $image = $this->soleImage($tenant, $examination);

        $this->assertSame(ExaminationImage::SOURCE_BROWSER_CAPTURE, $image->source);
        $this->assertNotNull($image->captured_at);
        $this->assertSame('OptoEdu Scout Camera', $image->instrument_label);
    }

    public function test_a_capture_without_a_device_label_falls_back_to_the_examination_instrument(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner, ['instrument' => 'LabScope 900']);

        $workspace = $this->workspace($tenant, $owner, $examination);
        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage();

        $this->bindTenant($tenant);
        $image = ExaminationImage::query()->where('examination_id', $examination->getKey())->sole();

        $this->assertSame(ExaminationImage::SOURCE_BROWSER_CAPTURE, $image->source);
        $this->assertNull($image->instrument_label);
        $this->assertSame('LabScope 900', $image->instrumentName());
    }

    public function test_an_agent_import_records_the_agent_source_and_label(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $this->bindTenant($tenant);
        $plain = app(IngestCredentialIssuer::class)
            ->issue($owner, (string) $tenant->getKey(), $examination, 'Microscope Export Agent')['plain'];

        $this->post('/api/lba/ingest', ['image' => $this->pngUpload()], [
            'Authorization' => 'Bearer '.$plain,
        ])->assertStatus(201);

        $image = $this->soleImage($tenant, $examination);

        $this->assertSame(ExaminationImage::SOURCE_AGENT_IMPORT, $image->source);
        $this->assertSame('Microscope Export Agent', $image->instrument_label);
    }

    public function test_an_unknown_source_falls_back_to_a_manual_import(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $this->bindTenant($tenant);

        app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner, [
            'source' => 'forged_source',
        ]);

        $this->assertSame(ExaminationImage::SOURCE_MANUAL_IMPORT, $this->soleImage($tenant, $examination)->source);
    }

    public function test_the_instrument_label_is_trimmed_and_bounded(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $this->bindTenant($tenant);

        app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner, [
            'source' => ExaminationImage::SOURCE_BROWSER_CAPTURE,
            'instrument_label' => '   '.str_repeat('A', 400).'   ',
        ]);

        $label = (string) $this->soleImage($tenant, $examination)->instrument_label;

        $this->assertLessThanOrEqual(120, strlen($label));
        $this->assertSame(str_repeat('A', 120), $label);
    }

    public function test_the_capture_moment_falls_back_to_the_intake_time(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $this->bindTenant($tenant);

        app(MicroscopyImageStore::class)->store($examination, $this->pngUpload(), $owner);

        $image = $this->soleImage($tenant, $examination);

        $this->assertNotNull($image->captureMoment());
        $this->assertTrue($image->captureMoment()->equalTo($image->created_at));
    }

    public function test_manual_annotation_works_without_any_ai_server(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $workspace = $this->workspace($tenant, $owner, $examination);
        $workspace->capturedImage = $this->pngUpload();
        $workspace->storeCapturedImage('Manual Camera');

        // A human annotation can be authored with no AI job involved.
        $workspace->createAnnotation('point', ['x' => 0.5, 'y' => 0.5]);

        $this->bindTenant($tenant);

        $image = $this->soleImage($tenant, $examination);

        $this->assertSame(1, $image->annotations()->count());
        $this->assertSame(0, $image->annotations()->whereNotNull('job_id')->count());
    }
}
