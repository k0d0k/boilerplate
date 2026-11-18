<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\LbaAssessment\Models\LbaIndicatorCatalogVersion;
use App\Platform\LbaAssessment\Models\LbaIndicatorDefinition;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\LBA\Assessment\LegacyIndicatorCatalog;
use Modules\LBA\Exceptions\AssessmentException;
use Modules\LBA\Filament\Pages\AssessmentForm;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentEvent;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\AssessmentCatalog;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 7A HOTFIX — focused security corrections (Modules/LBA).
 *
 * Covers ONLY the five hotfix behaviours:
 *  1. read-only access never creates an assessment or provisions the catalog;
 *  2. writes require EXAM_UPDATE under a verified resident tenant/branch;
 *  3. every mutation revalidates user/tenant/branch/membership/permissions;
 *  4. summary notes persist inside the verified tenant transaction;
 *  5. the 30 legacy labels stay reference-only and unvalidated.
 *
 * Every identity, patient and branch here is synthetic (DECISIONS.md PRV-01).
 */
class LbaAssessmentHotfixTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'lba';

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
            'full_name' => 'Synthetic Hotfix Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $overrides), $owner);
    }

    private function recorder(): AssessmentRecorder
    {
        return app(AssessmentRecorder::class);
    }

    /**
     * A page instance bound to one examination + assessment, driven at the
     * mutation boundary exactly as a Livewire update would (the Livewire update
     * endpoint is not routable inside this Filament/multi-tenant test harness).
     */
    private function page(Examination $examination, Assessment $assessment): AssessmentForm
    {
        $page = new AssessmentForm;
        $page->examinationId = (string) $examination->getKey();
        $page->assessmentId = (string) $assessment->getKey();

        return $page;
    }

    // =================================================== 1. READ-ONLY ACCESS

    public function test_a_read_only_identity_cannot_create_an_assessment_or_provision_the_catalog(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);
        $examination = $this->makeExamination($tenant, $owner, ['branch_id' => $branch->getKey()]);

        // CONSULTANT holds EXAM_READ only — never EXAM_UPDATE.
        $consultant = $this->member($tenant, RoleKey::CONSULTANT);
        $this->assignStaff($owner, $consultant, (string) $branch->getKey());

        $this->actingAs($consultant);
        $this->bindTenant($tenant);

        Livewire::test(AssessmentForm::class, ['examination' => (string) $examination->getKey()])
            ->assertOk()
            // No assessment exists, so the page loads read-only with NO id.
            ->assertSet('assessmentId', '');

        $this->bindTenant($tenant);

        // The read-only mount performed NO clinical write ...
        $this->assertSame(0, Assessment::query()->count());

        // ... and it did NOT provision the reference catalog (a central write).
        $this->assertSame(0, LbaIndicatorCatalogVersion::query()->count());
    }

    // =================================================== 2. WRITE AUTHORIZATION

    public function test_an_identity_without_exam_update_cannot_modify_an_existing_assessment(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);
        $examination = $this->makeExamination($tenant, $owner, ['branch_id' => $branch->getKey()]);
        $assessment = $this->recorder()->openFor($examination, $owner);

        $consultant = $this->member($tenant, RoleKey::CONSULTANT);
        $this->assignStaff($owner, $consultant, (string) $branch->getKey());

        $this->actingAs($consultant);
        $this->bindTenant($tenant);

        // The read-only identity CAN see the existing assessment (findFor) but
        // it never creates one ...
        Livewire::test(AssessmentForm::class, ['examination' => (string) $examination->getKey()])
            ->assertOk()
            ->assertSet('assessmentId', (string) $assessment->getKey());

        // ... and every mutation is refused.
        $this->page($examination, $assessment)
            ->setCategory('asthma_paru_tbc', AssessmentItem::CATEGORY_RED);

        $this->bindTenant($tenant);

        $this->assertSame(
            AssessmentItem::CATEGORY_UNASSESSED,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
            'An identity without EXAM_UPDATE must not modify the assessment.',
        );
    }

    // =================================================== 3. LIVEWIRE SECURITY

    public function test_revoking_the_branch_membership_invalidates_mutating_access(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant);
        $this->assignStaff($owner, $operator, (string) $branch->getKey());

        $examination = $this->makeExamination($tenant, $owner, ['branch_id' => $branch->getKey()]);
        $assessment = $this->recorder()->openFor($examination, $operator);

        $this->actingAs($operator);
        $this->bindTenant($tenant);

        $page = $this->page($examination, $assessment);
        $page->setCategory('asthma_paru_tbc', AssessmentItem::CATEGORY_GREEN);

        $this->bindTenant($tenant);
        $this->assertSame(
            AssessmentItem::CATEGORY_GREEN,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
        );

        // The operator's branch assignment is revoked between render and request.
        $this->revokeStaff($owner, $operator, (string) $branch->getKey());
        $this->bindTenant($tenant);

        $page->setCategory('asthma_paru_tbc', AssessmentItem::CATEGORY_RED);

        $this->bindTenant($tenant);
        $this->assertSame(
            AssessmentItem::CATEGORY_GREEN,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
            'A revoked identity must not mutate the assessment.',
        );
    }

    public function test_switching_the_verified_branch_invalidates_mutating_access(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branchA = $this->createBranch($tenant);
        $this->assignStaff($owner, $operator, (string) $branchA->getKey());

        $examination = $this->makeExamination($tenant, $owner, ['branch_id' => $branchA->getKey()]);
        $assessment = $this->recorder()->openFor($examination, $operator);

        $this->actingAs($operator);
        $this->bindTenant($tenant);

        $page = $this->page($examination, $assessment);
        $page->setCategory('asthma_paru_tbc', AssessmentItem::CATEGORY_GREEN);

        // A second branch the operator may enter, then switch into it.
        $branchC = $this->createBranch($tenant);
        $this->assignStaff($owner, $operator, (string) $branchC->getKey());

        $this->bindTenant($tenant);

        $this->assertTrue(
            app(ManagesBranchContext::class)->select($operator, (string) $branchC->getKey()),
        );

        $this->bindTenant($tenant);
        $page->setCategory('asthma_paru_tbc', AssessmentItem::CATEGORY_RED);

        $this->bindTenant($tenant);
        $this->assertSame(
            AssessmentItem::CATEGORY_GREEN,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
            'Switching branch must not allow mutating another branch\'s assessment.',
        );
    }

    public function test_switching_the_verified_tenant_invalidates_mutating_access(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenantA, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        $branchA = $this->createBranch($tenantA);
        $this->assignStaff($ownerA, $operator, (string) $branchA->getKey());

        $examination = $this->makeExamination($tenantA, $ownerA, ['branch_id' => $branchA->getKey()]);
        $assessment = $this->recorder()->openFor($examination, $operator);

        $this->actingAs($operator);
        $this->bindTenant($tenantA);

        $page = $this->page($examination, $assessment);
        $page->setCategory('asthma_paru_tbc', AssessmentItem::CATEGORY_GREEN);

        $this->bindTenant($tenantA);
        $this->assertSame(
            AssessmentItem::CATEGORY_GREEN,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
        );

        // The operator is also a member of a second tenant and switches to it.
        $tenantB = Tenant::factory()->create();
        $this->giveMembership($operator, $tenantB, [RoleKey::LBA_OPERATOR]);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB);

        $this->bindTenant($tenantB);
        $this->assertTrue(
            app(ManagesTenantContext::class)->select($operator, (string) $tenantB->getKey()),
        );

        $this->bindTenant($tenantB);
        $page->setCategory('asthma_paru_tbc', AssessmentItem::CATEGORY_RED);

        // The assessment is unreachable from the other tenant and unchanged.
        $this->bindTenant($tenantA);
        $this->assertSame(
            AssessmentItem::CATEGORY_GREEN,
            AssessmentItem::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('indicator_key', 'asthma_paru_tbc')
                ->value('category'),
            'Switching tenant must not allow mutating the first tenant\'s assessment.',
        );
    }

    // =================================================== 4. VERIFIED TRANSACTIONS

    public function test_summary_notes_persist_within_the_verified_tenant_transaction_with_events(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $assessment = $this->recorder()->openFor($examination, $owner);

        $this->recorder()->saveSummaryNotes($assessment, 'Ringkasan klinis awal.', $owner);

        $this->bindTenant($tenant);

        $this->assertSame(
            'Ringkasan klinis awal.',
            Assessment::query()->whereKey($assessment->getKey())->value('notes'),
        );

        $this->assertDatabaseHas('lba_assessment_events', [
            'assessment_id' => $assessment->getKey(),
            'action' => AssessmentEvent::ACTION_SUMMARY_NOTES_UPDATED,
            'actor_id' => $owner->getKey(),
        ], 'tenant');

        $this->assertDatabaseHas('lba_assessment_events', [
            'assessment_id' => $assessment->getKey(),
            'action' => AssessmentEvent::ACTION_DRAFT_SAVED,
        ], 'tenant');

        // Re-saving the unchanged summary appends a draft event but never a
        // duplicate summary-notes event.
        $this->recorder()->saveSummaryNotes($assessment->fresh(), 'Ringkasan klinis awal.', $owner);

        $this->bindTenant($tenant);

        $this->assertSame(
            1,
            AssessmentEvent::query()
                ->where('assessment_id', $assessment->getKey())
                ->where('action', AssessmentEvent::ACTION_SUMMARY_NOTES_UPDATED)
                ->count(),
        );

        // A locked assessment rejects the write and leaves the notes untouched.
        $this->recorder()->submitForReview($assessment->fresh(), $owner);

        try {
            $this->recorder()->saveSummaryNotes($assessment->fresh(), 'Discarded.', $owner);
            $this->fail('Summary notes must not persist on a locked assessment.');
        } catch (AssessmentException) {
            // expected
        }

        $this->bindTenant($tenant);

        $this->assertSame(
            'Ringkasan klinis awal.',
            Assessment::query()->whereKey($assessment->getKey())->value('notes'),
        );
    }

    // =================================================== 5. LEGACY INDICATOR SAFETY

    public function test_the_thirty_legacy_labels_are_preserved_in_order_and_stay_reference_only(): void
    {
        $definitions = LegacyIndicatorCatalog::definitions();

        $this->assertCount(30, $definitions);

        // Original order: left column 1–15, right column 16–30.
        $this->assertSame('asthma_paru_tbc', $definitions[0]['key']);
        $this->assertSame('leukimia_lupus_leukosit', $definitions[14]['key']);
        $this->assertSame('regenerasi_sel', $definitions[15]['key']);
        $this->assertSame('getah_benih_kelenjar_yeast', $definitions[29]['key']);
        $this->assertSame(
            range(1, 30),
            array_column($definitions, 'position'),
        );

        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner);
        $assessment = $this->recorder()->openFor($examination, $owner);

        $version = app(AssessmentCatalog::class)->activeVersionOrProvision();

        // Every catalog definition is explicitly marked unvalidated / reference-only.
        $this->assertSame(
            30,
            LbaIndicatorDefinition::query()
                ->where('catalog_version_id', $version->getKey())
                ->where('validation_status', LbaIndicatorDefinition::VALIDATION_REFERENCE_ONLY)
                ->whereNull('observation_label')
                ->count(),
        );

        $this->bindTenant($tenant);

        $items = AssessmentItem::query()
            ->where('assessment_id', $assessment->getKey())
            ->orderBy('position')
            ->get();

        $this->assertCount(30, $items);

        // The snapshot preserves the labels and their order, and every item is
        // reference-only (no reviewed observation label => not validated) and
        // unassessed (never a green/yellow/red clinical finding).
        foreach ($items as $index => $item) {
            $this->assertSame($definitions[$index]['key'], (string) $item->indicator_key);
            $this->assertSame($definitions[$index]['source_label'], (string) $item->source_label);
            $this->assertFalse($item->isValidated());
            $this->assertTrue($item->isReferenceOnly());
            $this->assertSame(AssessmentItem::CATEGORY_UNASSESSED, (string) $item->category);
        }
    }

    public function test_opening_an_ai_mode_assessment_never_generates_a_disease_classification(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $examination = $this->makeExamination($tenant, $owner, ['mode' => Examination::MODE_AI]);

        // Opening the assessment in AI mode must NOT classify any indicator: the
        // legacy labels are reference-only and AI never scores them.
        $assessment = $this->recorder()->openFor($examination, $owner);

        $this->bindTenant($tenant);

        $this->assertSame(0, AssessmentItem::query()
            ->where('assessment_id', $assessment->getKey())
            ->whereIn('category', AssessmentItem::RECORDABLE_CATEGORIES)
            ->count());

        // There is no stored diagnosis/classification column at all.
        $columns = Schema::connection('tenant')
            ->getColumnListing('lba_assessments');
        $this->assertNotContains('diagnosis', $columns);
        $this->assertNotContains('classification', $columns);
        $this->assertNotContains('risk_level', $columns);
    }
}
