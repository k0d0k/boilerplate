<?php

namespace Tests\Feature\Modules;

use App\Models\LbaIngestCredential;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Ingest\IngestCredentialIssuer;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS6.B — scoped agent image ingestion.
 *
 * A manufacturer-software export / watched-folder agent presents a SHORT-LIVED,
 * revocable bearer credential that is bound to ONE tenant, branch, operator and
 * a single editable examination. These tests prove the security contract:
 *
 *  - there is no public ingest — an absent / unknown / revoked / expired
 *    credential fails closed (401);
 *  - the agent can NEVER pick a tenant, patient or examination from untrusted
 *    input — scope comes solely from the credential row (wrong branch = 404, a
 *    client-supplied examination id is ignored);
 *  - every image still runs through the SAME validated, private, immutable
 *    intake path as a manual import (consent / editability / MIME / size);
 *  - duplicate content is deterministic and idempotent (200, no second row, the
 *    original is never overwritten);
 *  - one clinic's export can never land in another clinic's database;
 *  - only the SHA-256 hash of the secret is ever persisted.
 *
 * Only synthetic identities, patients and 1×1 images are used (DECISIONS.md
 * PRV-01).
 */
class LbaIngestTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'lba';

    private const ENDPOINT = '/api/lba/ingest';

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

    // --------------------------------------------------------------- Helpers

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
            'full_name' => 'Synthetic Ingest Patient',
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
     * Mint a scoped credential for one examination, exactly as the
     * "issue ingestion credential" operator action does.
     */
    private function issueCredential(User $operator, Tenant $tenant, Examination $examination): string
    {
        $this->bindTenant($tenant);

        return app(IngestCredentialIssuer::class)
            ->issue($operator, (string) $tenant->getKey(), $examination, 'Test Export Agent')['plain'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function ingest(string $plain, UploadedFile $file, array $data = [], array $headers = [])
    {
        $headers = array_merge(['Authorization' => 'Bearer '.$plain], $headers);

        return $this->post(self::ENDPOINT, array_merge(['image' => $file], $data), $headers);
    }

    private function imageCount(Tenant $tenant, Examination $examination): int
    {
        $this->bindTenant($tenant);

        return ExaminationImage::query()->where('examination_id', $examination->getKey())->count();
    }

    // ============================================================ 1. Admission

    public function test_ingest_requires_a_bearer_credential(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $this->post(self::ENDPOINT, ['image' => $this->pngUpload()])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_an_unknown_credential_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $this->ingest(str_repeat('x', 64), $this->pngUpload())
            ->assertStatus(401);

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_a_revoked_credential_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $credential = LbaIngestCredential::query()->firstOrFail();
        app(IngestCredentialIssuer::class)->revoke($credential, $owner);

        $this->ingest($plain, $this->pngUpload())->assertStatus(401);

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_an_expired_credential_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        LbaIngestCredential::query()->firstOrFail()
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->ingest($plain, $this->pngUpload())->assertStatus(401);

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_a_credential_fails_closed_once_the_operator_is_no_longer_a_member(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($operator, $tenant, $examination);

        TenantMembership::query()
            ->where('user_id', $operator->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->update(['status' => TenantMembership::STATUS_SUSPENDED]);

        $this->ingest($plain, $this->pngUpload())->assertStatus(401);

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    // ==================================================== 2. Untrusted-input safety

    public function test_a_client_supplied_examination_id_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examinationA = $this->makeExamination($tenant, $owner);
        $examinationB = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examinationA);

        // The agent tries to redirect the upload to another examination.
        $this->ingest($plain, $this->pngUpload(), [
            'examination_id' => (string) $examinationB->getKey(),
            'patient_id' => 'forged',
            'tenant_id' => 'forged',
        ])->assertStatus(201);

        $this->assertSame(1, $this->imageCount($tenant, $examinationA));
        $this->assertSame(0, $this->imageCount($tenant, $examinationB));
    }

    public function test_a_credential_cannot_target_another_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        // The examination is moved to a different branch after issuance, so the
        // credential's bound branch no longer matches: the request is refused.
        $this->bindTenant($tenant);
        $otherBranch = $this->createBranch($tenant);
        $examination->forceFill(['branch_id' => $otherBranch->getKey()])->save();

        $this->ingest($plain, $this->pngUpload())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    // ============================================================ 3. Intake gates

    public function test_issuing_is_refused_without_image_processing_consent(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner, ['record_consent' => false]);

        $this->expectException(ExaminationException::class);

        $this->issueCredential($owner, $tenant, $examination);
    }

    public function test_issuing_is_refused_for_an_operator_without_exam_update(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $this->expectException(ExaminationException::class);

        $this->issueCredential($receptionist, $tenant, $examination);
    }

    public function test_ingest_is_rejected_when_the_examination_is_no_longer_editable(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $this->bindTenant($tenant);
        $examination->forceFill(['status' => Examination::STATUS_REVIEW_REQUIRED])->save();

        $this->ingest($plain, $this->pngUpload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rejected');

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, 'not an image');
        $file = new UploadedFile($path, 'payload.png', 'image/png', null, true);

        $this->ingest($plain, $file)->assertStatus(422);

        $this->assertSame(0, $this->imageCount($tenant, $examination));
    }

    // ======================================================== 4. Success + idempotency

    public function test_an_authorised_agent_stores_an_image_privately(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $this->ingest($plain, $this->pngUpload())
            ->assertStatus(201)
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.width', 1)
            ->assertJsonPath('data.height', 1);

        $this->bindTenant($tenant);

        $image = ExaminationImage::query()->where('examination_id', $examination->getKey())->sole();

        $this->assertSame('lba', $image->disk);
        $this->assertStringStartsWith('examinations/', (string) $image->object_key);
        $this->assertNotEmpty($image->checksum);
        Storage::disk('lba')->assertExists((string) $image->object_key);

        // The draft examination advanced through the SAME private intake path.
        $this->assertSame(
            Examination::STATUS_UPLOADED,
            Examination::query()->whereKey($examination->getKey())->firstOrFail()->status,
        );

        // The credential's last-used timestamp is recorded.
        $this->assertNotNull(LbaIngestCredential::query()->firstOrFail()->last_used_at);

        // A successful ingest is audited (redacted) in the central database.
        $this->assertSame(1, ModuleAuditLog::query()
            ->where('action', 'ingest_request')
            ->where('result', ModuleAuditLog::RESULT_ALLOWED)
            ->count());
    }

    public function test_ingesting_identical_content_twice_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $first = $this->ingest($plain, $this->pngUpload())
            ->assertStatus(201)
            ->assertJsonPath('data.duplicate', false)
            ->json('data.id');

        $second = $this->ingest($plain, $this->pngUpload())
            ->assertStatus(200)
            ->assertJsonPath('data.duplicate', true)
            ->json('data.id');

        // Same immutable original is returned; no second row or object is made.
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->imageCount($tenant, $examination));
    }

    // ========================================================= 5. Tenant isolation

    public function test_an_export_from_one_clinic_never_lands_in_another(): void
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

        $plain = $this->issueCredential($ownerA, $tenantA, $examinationA);

        $this->ingest($plain, $this->pngUpload())->assertStatus(201);

        $this->assertSame(1, $this->imageCount($tenantA, $examinationA));
        $this->assertSame(0, $this->imageCount($tenantB, $examinationB));
    }

    // ========================================================= 6. Secret handling

    public function test_only_the_hashed_secret_is_persisted(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $this->assertDatabaseMissing('lba_ingest_credentials', ['token' => $plain]);
        $this->assertDatabaseHas('lba_ingest_credentials', [
            'token' => LbaIngestCredential::hash($plain),
        ]);

        $credential = LbaIngestCredential::query()->firstOrFail();

        $this->assertNotSame($plain, $credential->token);
        $this->assertSame((string) $tenant->getKey(), (string) $credential->tenant_id);
        $this->assertSame((string) $examination->getKey(), (string) $credential->examination_id);
        $this->assertContains(LbaIngestCredential::ABILITY_INGEST, (array) $credential->abilities);
    }

    // ============================================================== 7. Throttling

    public function test_ingest_is_rate_limited_per_credential(): void
    {
        config()->set('lba.ingest.rate_per_minute', 2);

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $this->ingest($plain, $this->pngUpload())->assertStatus(201);
        $this->ingest($plain, $this->pngUpload())->assertStatus(200);
        $this->ingest($plain, $this->pngUpload())->assertStatus(429);
    }

    // ================================ 8. WS6.1 duplicate cannot bypass gates

    /**
     * WS6.1 P0 — a repeat upload of identical content is only a "duplicate"
     * while the examination still accepts images. Once the examination leaves
     * the editable lifecycle, the SAME bytes are refused exactly like a first
     * upload: a checksum match must never be an editability bypass.
     */
    public function test_a_duplicate_upload_is_refused_once_the_examination_leaves_editable(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        // First delivery of the original succeeds.
        $this->ingest($plain, $this->pngUpload())->assertStatus(201);

        $this->bindTenant($tenant);
        $examination->forceFill(['status' => Examination::STATUS_REVIEW_REQUIRED])->save();

        $this->ingest($plain, $this->pngUpload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rejected');

        $this->assertSame(1, $this->imageCount($tenant, $examination));
    }

    /**
     * WS6.1 P0 — a withdrawn image-processing consent disables intake
     * immediately. The identical content that was accepted before withdrawal
     * cannot be re-delivered by leaning on the earlier checksum.
     */
    public function test_a_duplicate_upload_is_refused_after_image_consent_is_withdrawn(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $this->ingest($plain, $this->pngUpload())->assertStatus(201);

        $this->bindTenant($tenant);
        $examination->refresh();

        PatientConsent::query()
            ->whereKey($examination->consent_id)
            ->firstOrFail()
            ->withdraw();

        $this->ingest($plain, $this->pngUpload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rejected');

        $this->assertSame(1, $this->imageCount($tenant, $examination));
    }

    /**
     * A duplicate is NEVER an overwrite: the immutable original object and its
     * row are byte-for-byte untouched, and a duplicate does not re-register as
     * a fresh delivery of an original.
     */
    public function test_a_duplicate_never_overwrites_the_immutable_original(): void
    {
        $this->freezeTime();

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $first = $this->ingest($plain, $this->pngUpload())->assertStatus(201)->json('data');

        $this->bindTenant($tenant);

        $stored = ExaminationImage::query()->where('examination_id', $examination->getKey())->sole();
        $objectKey = (string) $stored->object_key;
        $before = (string) Storage::disk('lba')->get($objectKey);

        $usedAt = LbaIngestCredential::query()->firstOrFail()->last_used_at;

        $second = $this->ingest($plain, $this->pngUpload())->assertStatus(200)->json('data');

        // The request released the request-scoped tenant binding; re-bind to
        // inspect the tenant-owned original.
        $this->bindTenant($tenant);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($objectKey, (string) ExaminationImage::query()
            ->where('examination_id', $examination->getKey())
            ->sole()
            ->object_key);
        $this->assertSame($before, (string) Storage::disk('lba')->get($objectKey));
        $this->assertSame(1, $this->imageCount($tenant, $examination));

        // A duplicate is not a fresh original delivery, so it does not move the
        // credential's last-used timestamp.
        $this->assertSame(
            $usedAt?->toDateTimeString(),
            LbaIngestCredential::query()->firstOrFail()->last_used_at?->toDateTimeString(),
        );
    }

    /**
     * The checksum is scoped to (tenant, examination): identical bytes delivered
     * to two DIFFERENT examinations in the same clinic are two distinct
     * originals, never collapsed into one.
     */
    public function test_identical_content_in_two_examinations_is_not_deduplicated(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examinationA = $this->makeExamination($tenant, $owner);
        $examinationB = $this->makeExamination($tenant, $owner);

        $plainA = $this->issueCredential($owner, $tenant, $examinationA);
        $plainB = $this->issueCredential($owner, $tenant, $examinationB);

        $this->ingest($plainA, $this->pngUpload())
            ->assertStatus(201)
            ->assertJsonPath('data.duplicate', false);

        $this->ingest($plainB, $this->pngUpload())
            ->assertStatus(201)
            ->assertJsonPath('data.duplicate', false);

        $this->assertSame(1, $this->imageCount($tenant, $examinationA));
        $this->assertSame(1, $this->imageCount($tenant, $examinationB));
    }

    /**
     * The checksum can never leak across clinics: identical bytes delivered to
     * two clinics are two independent originals, each only visible in its own
     * tenant database.
     */
    public function test_identical_content_across_clinics_is_never_deduplicated(): void
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

        $plainA = $this->issueCredential($ownerA, $tenantA, $examinationA);
        $plainB = $this->issueCredential($ownerB, $tenantB, $examinationB);

        $this->ingest($plainA, $this->pngUpload())
            ->assertStatus(201)
            ->assertJsonPath('data.duplicate', false);

        $this->ingest($plainB, $this->pngUpload())
            ->assertStatus(201)
            ->assertJsonPath('data.duplicate', false);

        $this->assertSame(1, $this->imageCount($tenantA, $examinationA));
        $this->assertSame(1, $this->imageCount($tenantB, $examinationB));
    }

    // =================================== 9. WS6.1 file validation hardening

    /**
     * An oversized payload is refused BEFORE any bytes are buffered for a
     * checksum or any object is written.
     */
    public function test_an_oversized_upload_is_rejected_before_storage(): void
    {
        config()->set('lba.images.max_kilobytes', 1);

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, (string) base64_decode(self::PNG).str_repeat("\0", 8192));
        $file = new UploadedFile($path, 'large.png', 'image/png', null, true);

        $this->ingest($plain, $file)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rejected');

        $this->assertSame(0, $this->imageCount($tenant, $examination));
        $this->assertSame([], Storage::disk('lba')->allFiles());
    }

    /**
     * A payload that is not decodable image content is refused even when the
     * client supplies an image extension and an image content type — intake
     * trusts the ACTUAL bytes, never the client's claim.
     */
    public function test_a_masquerading_payload_is_rejected_despite_an_image_extension(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $examination = $this->makeExamination($tenant, $owner);

        $plain = $this->issueCredential($owner, $tenant, $examination);

        $path = tempnam(sys_get_temp_dir(), 'lba');
        file_put_contents($path, "<?php system(\$_GET['c']); ?>");
        $file = new UploadedFile($path, 'capture.png', 'image/png', null, true);

        $this->ingest($plain, $file)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rejected');

        $this->assertSame(0, $this->imageCount($tenant, $examination));
        $this->assertSame([], Storage::disk('lba')->allFiles());
    }
}
