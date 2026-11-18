<?php

namespace Tests\Feature\Modules;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\LBA\Exceptions\ReviewException;
use Modules\LBA\Filament\Pages\AssessmentReview;
use Modules\LBA\Filament\Pages\BrandReportSettings;
use Modules\LBA\Filament\Pages\ReviewerInbox;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ReportEvent;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Models\Review;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\ClinicalTerminologyGate;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\LBA\Services\ReportGenerator;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 7B — human review + private, versioned, multi-brand PDF reports
 * (focused essentials).
 *
 * Covers ONLY the required behaviours: reviewer permission + no self-approval,
 * tenant/branch isolation of reports, a returned assessment can be corrected,
 * no report may be issued without an explicit human approval, the 30 legacy
 * disease-oriented labels never reach a patient-facing clinical conclusion,
 * the report carries the configured brand identity + locale, versions are
 * immutable, downloads are private and checksum-verified, and ONE generated PDF
 * contains readable, selectable text. Every identity, patient and image here is
 * synthetic (DECISIONS.md PRV-01).
 */
class LbaReviewReportTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'lba';

    /** A real, decodable truecolour PNG (embeddable by dompdf). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWPkqG9ggAEmBiSAmwMAM+oBDwyuv+4AAAAASUVORK5CYII=';

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
            'full_name' => 'Synthetic Report Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $overrides), $owner);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function linkBrand(Tenant $tenant, array $identity = [], string $status = Brand::STATUS_ACTIVE): Brand
    {
        $brand = Brand::query()->create(array_merge([
            'name' => 'Brand '.Str::upper(Str::random(4)),
            'slug' => 'brand-'.Str::lower(Str::random(6)),
            'status' => $status,
        ], $identity));

        $brand->tenants()->attach($tenant->getKey(), ['is_primary' => true]);

        return $brand;
    }

    private function recorder(): AssessmentRecorder
    {
        return app(AssessmentRecorder::class);
    }

    private function reviewer(): AssessmentReviewer
    {
        return app(AssessmentReviewer::class);
    }

    /**
     * A submitted assessment owned by the operator, with a brand selected.
     *
     * @return array{0: Assessment, 1: Examination, 2: Brand}
     */
    private function submittedAssessment(Tenant $tenant, User $operator, array $brandIdentity = []): array
    {
        $examination = $this->makeExamination($tenant, $operator);
        $assessment = $this->recorder()->openFor($examination, $operator);
        $brand = $this->linkBrand($tenant, $brandIdentity);

        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_YELLOW, $operator);
        $this->recorder()->selectBrand($assessment, (string) $tenant->getKey(), (string) $brand->getKey(), $operator);
        $this->recorder()->submitForReview($assessment->fresh(), $operator);

        return [$assessment->fresh(), $examination, $brand];
    }

    /**
     * The readable text of a PDF: inflate every FlateDecode content stream and
     * concatenate. dompdf writes selectable text as `[(...)] TJ` operators, so a
     * human string surviving here proves the document carries selectable text
     * (rather than a rasterized image).
     */
    private function pdfText(string $bytes): string
    {
        // Resolve the page content stream(s) precisely, so embedded font
        // programs (large binaries that also happen to contain "BT" bytes) are
        // never mistaken for text.
        if (! preg_match_all('/\/Contents\s+(\d+)\s+0\s+R/', $bytes, $refs)) {
            return '';
        }

        $text = '';

        foreach (array_unique($refs[1]) as $id) {
            if (! preg_match('/\b'.$id.'\s+0\s+obj\b.*?stream\r?\n(.*?)\r?\nendstream/s', $bytes, $obj)) {
                continue;
            }

            $stream = $this->inflateStream($obj[1]);

            if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)/s', $stream, $literals)) {
                foreach ($literals[0] as $literal) {
                    $text .= $this->decodePdfString(stripcslashes(substr($literal, 1, -1)));
                }
            }
        }

        return $text;
    }

    private function inflateStream(string $raw): string
    {
        $inflated = @gzuncompress($raw);

        if ($inflated === false) {
            $inflated = @gzinflate($raw);
        }

        return $inflated === false ? $raw : $inflated;
    }

    /**
     * dompdf writes selectable text either as single-byte (WinAnsi) or as
     * two-byte UTF-16BE (Identity-H CID font) string literals. Decode the latter
     * so the human-readable characters can be asserted directly.
     */
    private function decodePdfString(string $value): string
    {
        if ($value !== '' && str_contains($value, "\x00")) {
            $decoded = @mb_convert_encoding($value, 'UTF-8', 'UTF-16BE');

            if (is_string($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    // ============================ 1. Reviewer permission + no self-approval

    public function test_only_a_reviewer_may_reach_the_review_surface_and_operators_lack_approval(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->bindTenant($tenant);
        $permissions = app(ChecksPermissions::class);
        $context = AccessContext::forTenant((string) $tenant->getKey());

        // Separation of duties: an operator may record but NEVER approve.
        $this->assertFalse($permissions->allows($operator, PermissionKey::EXAM_REVIEW, $context));
        $this->assertFalse($permissions->allows($operator, PermissionKey::REPORT_APPROVE, $context));
        $this->assertTrue($permissions->allows($reviewer, PermissionKey::EXAM_REVIEW, $context));
        $this->assertTrue($permissions->allows($reviewer, PermissionKey::REPORT_APPROVE, $context));

        // The review pages fail closed for the operator and open for the reviewer.
        $this->actingAs($operator);
        $this->bindTenant($tenant);
        $this->assertFalse(AssessmentReview::canAccess());
        $this->assertFalse(ReviewerInbox::canAccess());

        $this->actingAs($reviewer);
        $this->bindTenant($tenant);
        $this->assertTrue(AssessmentReview::canAccess());
        $this->assertTrue(ReviewerInbox::canAccess());
    }

    public function test_a_reviewer_may_not_approve_an_examination_they_recorded_or_operate(): void
    {
        $tenant = Tenant::factory()->create();
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // The reviewer recorded AND operates this examination.
        [$assessment] = $this->submittedAssessment($tenant, $reviewer);

        try {
            $this->reviewer()->approve($assessment, (string) $tenant->getKey(), $reviewer);
            $this->fail('A reviewer must not approve their own examination.');
        } catch (ReviewException $exception) {
            $this->assertStringContainsString('separate reviewer', strtolower($exception->getMessage()));
        }

        $this->bindTenant($tenant);

        $fresh = Assessment::query()->whereKey($assessment->getKey())->firstOrFail();
        $this->assertSame(Assessment::STATUS_SUBMITTED_FOR_REVIEW, $fresh->status);
        $this->assertSame(0, ReportVersion::query()->count());
        $this->assertSame(
            0,
            Review::query()->where('decision', Review::DECISION_APPROVED)->count(),
        );
    }

    // ==================================== 2. Explicit human approval + report

    public function test_a_distinct_reviewer_approval_issues_a_checksummed_private_version(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        [$assessment] = $this->submittedAssessment($tenant, $operator, [
            'report_display_name' => 'Babul Syiffa Clinic',
        ]);

        $version = $this->reviewer()->approve(
            $assessment,
            (string) $tenant->getKey(),
            $reviewer,
            'Observed and approved.',
        );

        $this->bindTenant($tenant);

        $this->assertInstanceOf(ReportVersion::class, $version);
        $this->assertSame(1, $version->version);
        $this->assertSame(ReportVersion::STATUS_GENERATED, $version->status);
        $this->assertSame(64, strlen((string) $version->checksum));
        $this->assertTrue($version->verifyChecksum());

        // Stored on the PRIVATE disk — never a public path or a public URL.
        Storage::disk((string) $version->disk)->assertExists((string) $version->object_key);
        $this->assertSame('lba-reports', $version->disk);
        $this->assertStringStartsWith('reports/', (string) $version->object_key);

        // Reviewer identity, time and history are preserved.
        $this->assertDatabaseHas('lba_reviews', [
            'assessment_id' => $assessment->getKey(),
            'reviewer_id' => $reviewer->getKey(),
            'decision' => Review::DECISION_APPROVED,
        ], 'tenant');

        $this->assertDatabaseHas('lba_report_events', [
            'assessment_id' => $assessment->getKey(),
            'report_version_id' => $version->getKey(),
            'action' => ReportEvent::ACTION_GENERATED,
            'actor_id' => $reviewer->getKey(),
        ], 'tenant');

        $fresh = Assessment::query()->whereKey($assessment->getKey())->firstOrFail();
        $this->assertSame(Assessment::STATUS_REVIEWED, $fresh->status);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_a_revision_creates_a_new_immutable_version_and_never_overwrites_the_first(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        [$assessment] = $this->submittedAssessment($tenant, $operator);

        $v1 = $this->reviewer()->approve($assessment, (string) $tenant->getKey(), $reviewer);
        $v2 = $this->reviewer()->generateReport($assessment->fresh(), (string) $tenant->getKey(), $reviewer);

        $this->bindTenant($tenant);

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertNotSame($v1->object_key, $v2->object_key);

        // Version 1 is byte-for-byte untouched and still verifies.
        $first = ReportVersion::query()->where('version', 1)->firstOrFail();
        $this->assertSame($v1->checksum, $first->checksum);
        $this->assertSame($v1->object_key, $first->object_key);
        $this->assertTrue($first->verifyChecksum());

        // The revision is recorded as a distinct event.
        $this->assertDatabaseHas('lba_report_events', [
            'assessment_id' => $assessment->getKey(),
            'report_version_id' => $v2->getKey(),
            'action' => ReportEvent::ACTION_REVISED,
        ], 'tenant');
    }

    public function test_no_report_can_be_issued_without_an_explicit_human_approval(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // Submitted but not yet approved.
        [$assessment] = $this->submittedAssessment($tenant, $operator);

        try {
            $this->reviewer()->generateReport($assessment, (string) $tenant->getKey(), $reviewer);
            $this->fail('A report must not be issued before a human approval.');
        } catch (ReviewException $exception) {
            $this->assertStringContainsString('human reviewer approves', strtolower($exception->getMessage()));
        }

        // Even with the status forced to `reviewed`, a missing approval RECORD
        // blocks generation — nothing (and no AI process) can approve silently.
        $assessment->forceFill([
            'status' => Assessment::STATUS_REVIEWED,
            'locked_at' => now(),
        ])->save();

        try {
            app(ReportGenerator::class)->generate($assessment->fresh(), (string) $tenant->getKey(), $reviewer);
            $this->fail('A report must require a recorded human approval.');
        } catch (ReviewException $exception) {
            $this->assertStringContainsString('human reviewer approves', strtolower($exception->getMessage()));
        }

        $this->bindTenant($tenant);

        $this->assertSame(0, ReportVersion::query()->count());
        $this->assertSame([], Storage::disk('lba-reports')->allFiles());
    }

    // ======================================= 3. Returned assessment corrected

    public function test_a_returned_assessment_can_be_corrected_and_resubmitted(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        [$assessment] = $this->submittedAssessment($tenant, $operator);

        $this->reviewer()->returnForChanges($assessment->fresh(), $reviewer, 'Please re-check the observation.');

        $this->bindTenant($tenant);

        $returned = Assessment::query()->whereKey($assessment->getKey())->firstOrFail();
        $this->assertSame(Assessment::STATUS_RETURNED_FOR_CHANGES, $returned->status);
        $this->assertNull($returned->locked_at);
        $this->assertTrue($returned->isEditable());

        $this->assertDatabaseHas('lba_reviews', [
            'assessment_id' => $assessment->getKey(),
            'reviewer_id' => $reviewer->getKey(),
            'decision' => Review::DECISION_RETURNED_FOR_CHANGES,
        ], 'tenant');

        // The operator can now correct the observation and re-submit.
        $this->recorder()->setCategory($returned, 'asthma_paru_tbc', AssessmentItem::CATEGORY_GREEN, $operator);
        $this->recorder()->submitForReview($returned->fresh(), $operator);

        $this->bindTenant($tenant);

        $this->assertSame(
            AssessmentItem::CATEGORY_GREEN,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
        );
        $this->assertSame(
            Assessment::STATUS_SUBMITTED_FOR_REVIEW,
            Assessment::query()->whereKey($assessment->getKey())->value('status'),
        );
    }

    // ============================== 4. Legacy labels never become findings

    public function test_legacy_disease_labels_are_excluded_from_patient_facing_findings(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $operator);
        $assessment = $this->recorder()->openFor($examination, $operator);
        $this->recorder()->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_RED, $operator);

        $this->bindTenant($tenant);

        $gate = app(ClinicalTerminologyGate::class);

        // A non-empty category is NOT a validated finding: every legacy label is
        // reference-only, so NO clinical finding may be produced.
        $this->assertSame([], $gate->clinicalFindings($assessment));
        $this->assertFalse($gate->hasReviewedTerminology($assessment));

        // The neutral observation record carries NO legacy disease label.
        $neutral = $gate->neutralObservations($assessment);
        $this->assertCount(30, $neutral);
        foreach ($neutral as $record) {
            $this->assertArrayNotHasKey('label', $record);
            $this->assertArrayNotHasKey('indicator_key', $record);
            $this->assertContains($record['status'], ['unassessed', 'reference_only']);
        }

        // The red selection is an INTERNAL review priority, never a risk level.
        $counts = $gate->neutralCategoryCounts($assessment);
        $this->assertSame(1, $counts[AssessmentItem::CATEGORY_RED]);

        // The legacy disease-oriented source label is preserved on the item but
        // is NOT the validated observation label.
        $item = AssessmentItem::query()
            ->where('assessment_id', $assessment->getKey())
            ->where('indicator_key', 'asthma_paru_tbc')
            ->firstOrFail();
        $this->assertFalse($item->isValidated());
        $this->assertTrue($item->isReferenceOnly());

        // A non-empty observation label ALONE is NOT a validated finding: an
        // explicit approved status AND a trustworthy approval provenance (who
        // and when) are required — a label is never sufficient on its own.
        $item->forceFill(['observation_label' => 'Reviewed observation'])->save();
        $this->assertFalse($item->fresh()->isValidated());
        $this->assertSame([], app(ClinicalTerminologyGate::class)->clinicalFindings($assessment->fresh()));

        // Only an explicit approved status PLUS approval provenance promotes it.
        $item->forceFill([
            'terminology_status' => AssessmentItem::TERMINOLOGY_APPROVED,
            'terminology_approved_by' => 'reviewer-ulid',
            'terminology_approved_at' => now(),
        ])->save();

        $findings = app(ClinicalTerminologyGate::class)->clinicalFindings($assessment->fresh());
        $this->assertCount(1, $findings);
        $this->assertSame('Reviewed observation', $findings[0]['label']);
    }

    // ==================================== 5. Brand identity, logo, locale

    public function test_the_report_carries_the_configured_brand_identity_logo_and_locale(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // A private brand logo on the report disk.
        Storage::disk('lba-reports')->put('brand-logos/babul.png', (string) base64_decode(self::PNG));

        [$assessment] = $this->submittedAssessment($tenant, $operator, [
            'report_display_name' => 'Babul Syiffa Report',
            'report_address' => 'Jl. Merdeka No. 1, Jakarta',
            'report_contact' => '021-555-0100',
            'report_website' => 'https://babulsyiffa.example',
            'report_language' => 'en',
            'report_logo_disk' => 'lba-reports',
            'report_logo_path' => 'brand-logos/babul.png',
        ]);

        // The brand default language is applied when no locale is requested.
        $v1 = $this->reviewer()->approve($assessment, (string) $tenant->getKey(), $reviewer);

        $this->bindTenant($tenant);

        $this->assertSame('en', $v1->locale);
        $this->assertSame('Babul Syiffa Report', $v1->brand_snapshot['display_name']);
        $this->assertSame('Jl. Merdeka No. 1, Jakarta', $v1->brand_snapshot['address']);
        $this->assertSame('brand-logos/babul.png', $v1->brand_snapshot['logo_path']);
        $this->assertSame((string) $reviewer->name, $v1->reviewer_snapshot['name']);

        // An explicit supported locale overrides the brand default.
        $v2 = $this->reviewer()->generateReport($assessment->fresh(), (string) $tenant->getKey(), $reviewer, 'ms-MY');
        $this->bindTenant($tenant);
        $this->assertSame('ms-MY', $v2->locale);

        // The logo is embedded (a real image object) into the PDF, not linked.
        $bytes = Storage::disk('lba-reports')->get((string) $v1->object_key);
        $this->assertStringContainsString('/Image', $bytes);

        // An unsupported locale falls back to the brand / platform default.
        $v3 = $this->reviewer()->generateReport($assessment->fresh(), (string) $tenant->getKey(), $reviewer, 'fr');
        $this->bindTenant($tenant);
        $this->assertSame('en', $v3->locale);
    }

    // ========================== 6. Private, branch-scoped download + checksum

    public function test_reports_are_private_and_isolated_across_tenants_and_branches(): void
    {
        // --- Tenant A: branch A + branch C, both reviewers distinct from operator.
        $tenantA = Tenant::factory()->create();
        $operatorA = $this->member($tenantA, RoleKey::LBA_OPERATOR);
        $reviewerA = $this->member($tenantA, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $branchA = $this->createBranch($tenantA);
        $branchC = $this->createBranch($tenantA);
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);

        $examination = $this->makeExamination($tenantA, $operatorA, ['branch_id' => $branchA->getKey()]);
        $assessment = $this->recorder()->openFor($examination, $operatorA);
        $brand = $this->linkBrand($tenantA);

        $this->recorder()->selectBrand($assessment, (string) $tenantA->getKey(), (string) $brand->getKey(), $operatorA);
        $this->recorder()->submitForReview($assessment->fresh(), $operatorA);

        $version = $this->reviewer()->approve($assessment->fresh(), (string) $tenantA->getKey(), $reviewerA);

        // The reviewer may operate only in branch A.
        $this->assignStaff($ownerA, $reviewerA, (string) $branchA->getKey());

        $this->actingAs($reviewerA);
        $this->bindTenant($tenantA);

        $response = $this->get(route('lba.reports.download', ['report' => $version->getKey()]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('nosniff', (string) $response->headers->get('X-Content-Type-Options'));

        $bytes = $response->streamedContent();
        $this->assertStringStartsWith('%PDF', $bytes);

        // The access is appended to the report audit trail.
        $this->bindTenant($tenantA);
        $this->assertDatabaseHas('lba_report_events', [
            'report_version_id' => $version->getKey(),
            'action' => ReportEvent::ACTION_DOWNLOADED,
            'actor_id' => $reviewerA->getKey(),
        ], 'tenant');

        // --- Cross-branch: a reviewer scoped to branch C cannot read branch A's report.
        $reviewerC = $this->member($tenantA, RoleKey::REVIEWER);
        $this->assignStaff($ownerA, $reviewerC, (string) $branchC->getKey());

        $this->actingAs($reviewerC);
        $this->bindTenant($tenantA);
        $this->get(route('lba.reports.download', ['report' => $version->getKey()]))->assertNotFound();

        // --- Cross-tenant: tenant B may never see tenant A's report.
        $tenantB = Tenant::factory()->create();
        $operatorB = $this->member($tenantB, RoleKey::LBA_OPERATOR);
        $reviewerB = $this->member($tenantB, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);
        $branchB = $this->createBranch($tenantB);
        $ownerB = $this->member($tenantB, RoleKey::TENANT_OWNER);
        $this->assignStaff($ownerB, $reviewerB, (string) $branchB->getKey());

        $this->actingAs($reviewerB);
        $this->bindTenant($tenantB);
        $this->get(route('lba.reports.download', ['report' => $version->getKey()]))->assertNotFound();

        // --- A tampered object fails the integrity check and is not served.
        Storage::disk('lba-reports')->put((string) $version->object_key, '%PDF-1.4 tampered');
        $this->actingAs($reviewerA);
        $this->bindTenant($tenantA);
        $this->get(route('lba.reports.download', ['report' => $version->getKey()]))->assertNotFound();
    }

    // =================================== 7. ONE PDF with selectable text

    public function test_one_generated_pdf_contains_readable_selectable_text(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        [$assessment] = $this->submittedAssessment($tenant, $operator, [
            'report_display_name' => 'Babul Syiffa Report',
        ]);

        $version = $this->reviewer()->approve($assessment, (string) $tenant->getKey(), $reviewer);

        $this->bindTenant($tenant);

        $bytes = Storage::disk((string) $version->disk)->get((string) $version->object_key);

        $this->assertStringStartsWith('%PDF', $bytes);

        $text = $this->pdfText($bytes);

        // The human strings are readable inside the page content stream, which
        // proves the document carries selectable text (not one rasterized image).
        $this->assertStringContainsString('Babul Syiffa Report', $text);
        $this->assertStringContainsString('Synthetic Report Patient', $text);
    }

    // ==================================== 8. Patient-facing PDF neutrality

    public function test_patient_facing_report_never_prints_unvalidated_severity_tallies(): void
    {
        // The exact patient-facing template, rendered for an assessment whose
        // only item is an UNVALIDATED legacy disease-oriented category.
        $html = view('lba::reports.assessment', [
            'locale' => 'en',
            'version' => 1,
            'brand' => ['name' => 'Synthetic Brand', 'display_name' => 'Synthetic Brand'],
            'branch' => [],
            'reviewer' => [],
            'patient' => ['name' => 'Synthetic Patient', 'number' => '—'],
            'examination' => ['id' => '—', 'mode' => 'manual', 'captured_at' => null],
            'summary' => [
                'findings' => [],
                'neutral' => [['position' => 1, 'category' => 'red', 'status' => 'reference_only']],
                'has_reviewed_terminology' => false,
                'pending' => 1,
            ],
            'program_phase' => Assessment::PROGRAM_NOT_SPECIFIED,
            'evidence' => [],
            'item_count' => 1,
            'generated_at' => '2026-01-01T00:00:00+00:00',
        ])->render();

        // A neutral observation/limitations statement is rendered instead.
        $this->assertStringContainsString(__('lba.report.observation_summary_unvalidated'), $html);

        // No green/yellow/red tally from unvalidated categories is presented as
        // a clinical severity or disease risk.
        $this->assertStringNotContainsString(__('lba.assessment.category_green'), $html);
        $this->assertStringNotContainsString(__('lba.assessment.category_yellow'), $html);
        $this->assertStringNotContainsString(__('lba.assessment.category_red'), $html);
    }

    // ==================================== 9. Brand settings write authority

    public function test_brand_settings_cannot_be_edited_by_a_linked_tenant_and_are_reauthorized(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $brand = $this->linkBrand($tenant);

        $this->actingAs($owner);
        $this->bindTenant($tenant);

        // The owner reaches the page (tenant profile manage) but a direct
        // Livewire action must NOT mutate the SHARED global brand: the tenant
        // link alone is not authority to edit global brand settings.
        $page = new BrandReportSettings;
        $page->brandId = (string) $brand->getKey();
        $page->displayName = 'Hijacked Global Name';
        $page->save();

        $this->assertNull(Brand::query()->findOrFail($brand->getKey())->report_display_name);

        // A platform brand manager (brand.metadata.manage) re-authorizes and may
        // persist. An active membership in a verified tenant is still required.
        $manager = User::factory()->create();
        $this->grantPrincipalRole($manager, RoleKey::BRAND_MANAGER);
        $this->giveMembership($manager, $tenant);

        $this->actingAs($manager);
        $this->bindTenant($tenant);

        $page = new BrandReportSettings;
        $page->brandId = (string) $brand->getKey();
        $page->displayName = 'Managed Global Name';
        $page->save();

        $this->assertSame('Managed Global Name', Brand::query()->findOrFail($brand->getKey())->report_display_name);
    }

    // ==================================== 10. Service-level authorization

    public function test_review_and_report_services_reject_unauthorized_calls_outside_the_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        [$assessment] = $this->submittedAssessment($tenant, $operator);

        $tenantId = (string) $tenant->getKey();

        // An operator lacks report.approve / exam.review, so EVERY service
        // entry point rejects the call even though it never passed the UI.
        foreach ([
            fn () => $this->reviewer()->approve($assessment->fresh(), $tenantId, $operator),
            fn () => $this->reviewer()->generateReport($assessment->fresh(), $tenantId, $operator),
            fn () => $this->reviewer()->returnForChanges($assessment->fresh(), $operator),
            fn () => $this->reviewer()->reject($assessment->fresh(), $operator),
            fn () => app(ReportGenerator::class)->generate($assessment->fresh(), $tenantId, $operator),
        ] as $call) {
            try {
                $call();
                $this->fail('An unauthorized service call must be rejected.');
            } catch (ReviewException $exception) {
                $this->assertStringContainsString('not authorised', strtolower($exception->getMessage()));
            }
        }

        $this->bindTenant($tenant);

        // Nothing was written: the assessment is untouched and no report exists.
        $this->assertSame(Assessment::STATUS_SUBMITTED_FOR_REVIEW, Assessment::query()->whereKey($assessment->getKey())->value('status'));
        $this->assertSame(0, Review::query()->count());
        $this->assertSame(0, ReportVersion::query()->count());
    }

    // ==================================== 11. Retry-safe report generation

    public function test_an_interrupted_report_generation_can_be_retried_without_losing_the_approval(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        [$assessment] = $this->submittedAssessment($tenant, $operator);

        // Simulate a generation interrupted AFTER the human approval committed:
        // the approval is recorded and the assessment is reviewed, but the PDF
        // was never written (no report version exists).
        Review::query()->create([
            'assessment_id' => $assessment->getKey(),
            'reviewer_id' => (string) $reviewer->getKey(),
            'reviewer_snapshot' => [
                'id' => (string) $reviewer->getKey(),
                'name' => (string) $reviewer->name,
                'email' => (string) $reviewer->email,
            ],
            'decision' => Review::DECISION_APPROVED,
            'notes' => 'Approved before the interruption.',
            'decided_at' => now(),
        ]);

        $assessment->forceFill([
            'status' => Assessment::STATUS_REVIEWED,
            'reviewed_at' => now(),
            'locked_at' => now(),
        ])->save();

        $tenantId = (string) $tenant->getKey();

        // Retrying resumes generation instead of failing "not reviewable", and
        // does NOT record a second approval.
        $v1 = $this->reviewer()->approve($assessment->fresh(), $tenantId, $reviewer);

        $this->bindTenant($tenant);

        $this->assertSame(1, $v1->version);
        $this->assertSame(1, Review::query()->where('decision', Review::DECISION_APPROVED)->count());
        $this->assertSame(1, ReportVersion::query()->count());

        // A further retry returns the existing immutable version — never a
        // duplicate and never an overwrite of the issued document.
        $again = $this->reviewer()->approve($assessment->fresh(), $tenantId, $reviewer);

        $this->bindTenant($tenant);

        $this->assertSame($v1->getKey(), $again->getKey());
        $this->assertSame(1, ReportVersion::query()->count());
        $this->assertSame($v1->object_key, ReportVersion::query()->firstOrFail()->object_key);
    }
}
