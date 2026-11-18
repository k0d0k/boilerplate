<?php

namespace Tests\Feature\Modules;

use App\Filament\Shared\Navigation\LbaPreviewNavigation;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\LBA\Filament\Pages\AiWalletOverview;
use Modules\LBA\Filament\Pages\AssessmentReview;
use Modules\LBA\Filament\Pages\BrandReportSettings;
use Modules\LBA\Filament\Pages\ReportsOverview;
use Modules\LBA\Filament\Pages\ReviewerInbox;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\ListAiExaminations;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\ListHybridExaminations;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\ListManualExaminations;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 7E — Clinic operational menus (focused essentials).
 *
 * Proves that the existing Clinic functionality is exposed through REAL, working
 * Filament navigation and nothing more:
 *
 *  1. the Manual / AI / Hybrid mode queues, the reviewer inbox, the reports
 *     archive and the clinic settings page are all route-backed and carry
 *     unique navigation sorts (no duplicate menu items);
 *  2. every Clinic module now ships, so the non-interactive preview group is
 *     gone entirely (it would otherwise duplicate live navigation);
 *  3. the new surfaces enforce RBAC and never cross tenant or branch boundaries;
 *  4. ONE complete Manual LBA → Review → PDF flow works end to end, using only
 *     the existing private report services (no AI, no auto-diagnosis).
 *
 * Every identity, patient and examination here is synthetic (DECISIONS.md PRV-01).
 */
class LbaClinicNavigationTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'lba';

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

    private function makeExamination(Tenant $tenant, User $owner, ?string $branchId = null): Examination
    {
        $this->bindTenant($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Navigation Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
            'branch_id' => $branchId ?? $this->createBranch($tenant)->getKey(),
        ], $owner);
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

    /**
     * A submitted manual assessment owned by the operator, with a brand selected.
     *
     * @return array{0: Assessment, 1: Examination, 2: Brand}
     */
    private function submittedAssessment(Tenant $tenant, User $operator, ?string $branchId = null): array
    {
        $examination = $this->makeExamination($tenant, $operator, $branchId);
        $assessment = app(AssessmentRecorder::class)->openFor($examination, $operator);
        $brand = $this->linkBrand($tenant);

        app(AssessmentRecorder::class)->setCategory($assessment, 'asthma_paru_tbc', AssessmentItem::CATEGORY_YELLOW, $operator);
        app(AssessmentRecorder::class)->selectBrand($assessment, (string) $tenant->getKey(), (string) $brand->getKey(), $operator);
        app(AssessmentRecorder::class)->submitForReview($assessment->fresh(), $operator);

        return [$assessment->fresh(), $examination, $brand];
    }

    private function reviewer(): AssessmentReviewer
    {
        return app(AssessmentReviewer::class);
    }

    // ============================================ 1. Real, working navigation

    public function test_the_clinic_operational_menus_are_route_backed_and_unique(): void
    {
        // The Manual / AI / Hybrid mode queues are real routes on the registry.
        foreach ([
            'filament.clinic.resources.examinations.index',
            'filament.clinic.resources.examinations.manual',
            'filament.clinic.resources.examinations.ai',
            'filament.clinic.resources.examinations.hybrid',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route [{$name}].");
        }

        // Review, reports and clinic settings are real, route-backed pages.
        foreach ([
            'filament.clinic.pages.lba.reviews',
            'filament.clinic.pages.lba.review',
            'filament.clinic.pages.lba.reports',
            'filament.clinic.pages.lba.report-brands',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route [{$name}].");
        }

        // The AI / Hybrid queues narrow the registry to their own mode.
        $this->assertSame(__('lba.exams.nav_manual'), ListManualExaminations::getNavigationLabel());
        $this->assertSame(__('lba.exams.nav_ai'), ListAiExaminations::getNavigationLabel());
        $this->assertSame(__('lba.exams.nav_hybrid'), ListHybridExaminations::getNavigationLabel());

        // No duplicate menu items: every Clinic LBA entry has a unique sort.
        $sorts = array_map(
            static fn (string $class): ?int => $class::getNavigationSort(),
            [
                ExaminationResource::class,
                ListManualExaminations::class,
                ListAiExaminations::class,
                ListHybridExaminations::class,
                ReviewerInbox::class,
                ReportsOverview::class,
                BrandReportSettings::class,
                AiWalletOverview::class,
            ],
        );

        $this->assertNotContains(null, $sorts, 'Every Clinic LBA entry must declare a navigation sort.');
        $this->assertCount(
            count($sorts),
            array_unique($sorts),
            'Clinic LBA navigation sorts must be unique (no duplicate menu items).',
        );
    }

    // ============================================ 2. Preview group trimmed

    public function test_shipped_clinic_pages_are_removed_from_the_preview_group(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        $response = $this->actingAs($owner)->get('/clinic')->assertOk();

        // Every Clinic module (Patients, Appointments, LBA, Consultations,
        // Commerce) now ships real, route-backed navigation, so the
        // non-interactive preview group is gone entirely — keeping it would only
        // duplicate the live "Consultations" / "Products & Orders" entries with
        // non-clickable labels.
        $response->assertDontSee(LbaPreviewNavigation::previewGroupLabel());
        $response->assertDontSee('href="#"', false);
        $this->assertSame(
            0,
            substr_count($response->getContent(), 'lba-preview-item__btn'),
            'No preview labels may remain once every Clinic module ships.',
        );
    }

    // ============================================ 3. RBAC

    public function test_the_reports_and_settings_pages_enforce_rbac(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // The reports archive is readable by any identity holding exam.read.
        foreach ([$owner, $operator, $reviewer] as $identity) {
            $this->actingAs($identity);
            $this->bindTenant($tenant);
            $this->assertTrue(ReportsOverview::canAccess(), 'exam.read identity must reach the reports archive.');
        }

        // Clinic settings require tenant profile management: the owner may, the
        // operator may not.
        $this->actingAs($owner);
        $this->bindTenant($tenant);
        $this->assertTrue(BrandReportSettings::canAccess());

        $this->actingAs($operator);
        $this->bindTenant($tenant);
        $this->assertFalse(BrandReportSettings::canAccess());
    }

    // ============================================ 4. Tenant + branch isolation

    public function test_the_reports_archive_is_tenant_and_branch_isolated(): void
    {
        // Tenant A: branch A + branch C, one approved report in branch A.
        $tenantA = Tenant::factory()->create();
        $operatorA = $this->member($tenantA, RoleKey::LBA_OPERATOR);
        $reviewerA = $this->member($tenantA, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $branchA = $this->createBranch($tenantA);
        $branchC = $this->createBranch($tenantA);
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);

        [$assessment] = $this->submittedAssessment($tenantA, $operatorA, (string) $branchA->getKey());
        $version = $this->reviewer()->approve($assessment, (string) $tenantA->getKey(), $reviewerA);

        // A reviewer operating branch A sees the report.
        $this->assignStaff($ownerA, $reviewerA, (string) $branchA->getKey());
        $this->actingAs($reviewerA);
        $this->bindTenant($tenantA);
        $this->assertTrue(
            (new ReportsOverview)->reports()->contains(fn ($row) => $row->getKey() === $version->getKey()),
        );

        // A reviewer scoped to branch C never sees branch A's report.
        $reviewerC = $this->member($tenantA, RoleKey::REVIEWER);
        $this->assignStaff($ownerA, $reviewerC, (string) $branchC->getKey());
        $this->actingAs($reviewerC);
        $this->bindTenant($tenantA);
        $this->assertFalse(
            (new ReportsOverview)->reports()->contains(fn ($row) => $row->getKey() === $version->getKey()),
        );

        // Another tenant's archive is empty — cross-tenant isolation.
        $tenantB = Tenant::factory()->create();
        $reviewerB = $this->member($tenantB, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);
        $branchB = $this->createBranch($tenantB);
        $ownerB = $this->member($tenantB, RoleKey::TENANT_OWNER);
        $this->assignStaff($ownerB, $reviewerB, (string) $branchB->getKey());

        $this->actingAs($reviewerB);
        $this->bindTenant($tenantB);
        $this->assertTrue((new ReportsOverview)->reports()->isEmpty());
    }

    // ============================================ 5. Complete Manual flow

    public function test_a_complete_manual_lba_review_to_pdf_flow(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->assignStaff($owner, $reviewer, (string) $branch->getKey());

        // 1. A MANUAL examination is recorded and submitted for review.
        [$assessment, $examination] = $this->submittedAssessment($tenant, $operator, (string) $branch->getKey());

        $this->bindTenant($tenant);
        $this->assertSame(Examination::MODE_MANUAL, $examination->mode);
        $this->assertSame(Assessment::STATUS_SUBMITTED_FOR_REVIEW, $assessment->status);

        // 2. The reviewer sees it in the inbox, and only the reviewer may decide.
        $this->actingAs($operator);
        $this->bindTenant($tenant);
        $this->assertFalse(ReviewerInbox::canAccess());

        $this->actingAs($reviewer);
        $this->bindTenant($tenant);
        $this->assertTrue(ReviewerInbox::canAccess());
        $this->assertTrue(
            (new ReviewerInbox)->pending()->contains(fn ($row) => $row->getKey() === $assessment->getKey()),
        );

        // 3. The review page mounts and the human approval issues version 1.
        Livewire::test(AssessmentReview::class, ['assessment' => (string) $assessment->getKey()])->assertOk();

        $version = $this->reviewer()->approve($assessment->fresh(), (string) $tenant->getKey(), $reviewer, 'Reviewed.');

        $this->bindTenant($tenant);
        $this->assertSame(1, $version->version);
        $this->assertTrue($version->verifyChecksum());

        // 4. The approved report appears in the archive, and the page renders.
        $this->assertTrue(
            (new ReportsOverview)->reports()->contains(fn ($row) => $row->getKey() === $version->getKey()),
        );

        Livewire::test(ReportsOverview::class)->assertOk();

        // 5. The private PDF streams only to the authorised identity.
        $response = $this->get(route('lba.reports.download', ['report' => $version->getKey()]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }
}
