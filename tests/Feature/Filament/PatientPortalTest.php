<?php

namespace Tests\Feature\Filament;

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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ReportEvent;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Exceptions\PatientLinkException;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 8A — Patient self-service portal (focused essentials).
 *
 * Exercises the server-enforced security boundary of the Patient panel: the
 * tenant-context gateway (a patient identity is always scoped to a partner
 * clinic through an active membership), and self-only resource authorization
 * on appointments, reports and the private report stream. Only synthetic
 * identities, patients and clinical objects are used (DECISIONS.md PRV-01).
 */
class PatientPortalTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('patient'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    private function patientUser(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        return $user;
    }

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

    private function registerPatient(Tenant $tenant, ?User $boundUser, string $name): Patient
    {
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        // `user_id` is deliberately NOT mass-assignable, so it is never passed
        // through the registrar; a bound account is only ever attached through
        // the authorized, proof-based linker below.
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => $name,
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        if ($boundUser !== null) {
            $this->bindPatient($tenant, $patient, $boundUser, $owner);
        }

        return $patient;
    }

    /**
     * Bind a portal account the way production does: an authorized clinic
     * identity issues a single-use invitation proof, which the account redeems.
     */
    private function bindPatient(Tenant $tenant, Patient $patient, User $account, User $actor): void
    {
        $this->bindTenant($tenant);

        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $actor);

        app(PatientAccountLinker::class)->redeemInvitation($account, $code);
    }

    /**
     * Approve a report for the given patient and return the issued version.
     */
    private function approvedReportFor(Tenant $tenant, Patient $patient, string $branchName): ReportVersion
    {
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);

        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant, ['name' => $branchName]);

        $examination = app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $operator);

        $assessment = app(AssessmentRecorder::class)->openFor($examination, $operator);

        $brand = Brand::query()->create([
            'name' => 'Brand '.Str::upper(Str::random(4)),
            'slug' => 'brand-'.Str::lower(Str::random(6)),
            'status' => Brand::STATUS_ACTIVE,
        ]);
        $brand->tenants()->attach($tenant->getKey(), ['is_primary' => true]);

        app(AssessmentRecorder::class)->selectBrand($assessment, (string) $tenant->getKey(), (string) $brand->getKey(), $operator);
        app(AssessmentRecorder::class)->submitForReview($assessment->fresh(), $operator);

        return app(AssessmentReviewer::class)->approve($assessment->fresh(), (string) $tenant->getKey(), $reviewer);
    }

    /**
     * Approve a report AND explicitly release it to the patient portal.
     */
    private function releasedReportFor(Tenant $tenant, Patient $patient, string $branchName, ?User $actor = null): ReportVersion
    {
        $version = $this->approvedReportFor($tenant, $patient, $branchName);

        $actor ??= $this->member($tenant, RoleKey::REVIEWER);

        $this->bindTenant($tenant);

        return app(AssessmentReviewer::class)->releaseToPatient($version, (string) $tenant->getKey(), $actor);
    }

    // ========================================= 1. Tenant-context gateway

    public function test_the_patient_portal_is_denied_without_any_membership(): void
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();

        $this->actingAs($user)->get('/patient')->assertForbidden();
    }

    public function test_a_scoped_patient_reaches_the_portal_with_a_single_membership(): void
    {
        $user = $this->patientUser(Tenant::factory()->create());

        $this->actingAs($user)
            ->get('/patient')
            ->assertOk()
            ->assertSee('Beranda Pasien');
    }

    public function test_multiple_memberships_are_redirected_to_explicit_clinic_selection(): void
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, Tenant::factory()->create(), [RoleKey::PATIENT_CUSTOMER]);
        $this->giveMembership($user, Tenant::factory()->create(), [RoleKey::PATIENT_CUSTOMER]);

        $this->actingAs($user)->get('/patient')->assertRedirect('/patient/pilih-klinik');
    }

    public function test_the_clinic_selection_screen_is_denied_without_a_membership(): void
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();

        $this->actingAs($user)->get('/patient/pilih-klinik')->assertForbidden();
    }

    public function test_the_clinic_selection_screen_lists_only_the_authorized_clinics(): void
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, Tenant::factory()->create(['name' => 'Klinik Alpha']), [RoleKey::PATIENT_CUSTOMER]);
        $this->giveMembership($user, Tenant::factory()->create(['name' => 'Klinik Beta']), [RoleKey::PATIENT_CUSTOMER]);
        // A foreign tenant the identity has no membership in must never appear.
        Tenant::factory()->create(['name' => 'Klinik Asing']);

        $this->actingAs($user)
            ->get('/patient/pilih-klinik')
            ->assertOk()
            ->assertSee('Klinik Alpha')
            ->assertSee('Klinik Beta')
            ->assertDontSee('Klinik Asing');
    }

    // ========================================= 2. Self-only appointments

    public function test_my_appointments_lists_only_the_acting_patients_own_appointments(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Appointment');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Appointment');

        $ownBranch = $this->createBranch($tenant, ['name' => 'Cabang Sendiri']);
        $foreignBranch = $this->createBranch($tenant, ['name' => 'Cabang Asing']);

        $starts = now()->addDay();

        app(AppointmentScheduler::class)->create([
            'patient_id' => $own->getKey(),
            'branch_id' => $ownBranch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes(30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $user);

        app(AppointmentScheduler::class)->create([
            'patient_id' => $foreign->getKey(),
            'branch_id' => $foreignBranch->getKey(),
            'starts_at' => $starts->copy()->addHour(),
            'ends_at' => $starts->copy()->addHour()->addMinutes(30),
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $user);

        $this->actingAs($user)
            ->get('/patient/janji-saya')
            ->assertOk()
            ->assertSee('Cabang Sendiri')
            ->assertDontSee('Cabang Asing');
    }

    // ========================================= 3. Self-only released reports

    public function test_my_results_lists_only_the_acting_patients_own_released_reports(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Report');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Report');

        // The patient's own report, explicitly RELEASED by the clinic, is
        // visible...
        $this->releasedReportFor($tenant, $own, 'Cabang Terbit');

        // ...an own examination whose assessment is NOT yet approved has no
        // report version at all, so it can never surface.
        $this->bindTenant($tenant);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $draftBranch = $this->createBranch($tenant, ['name' => 'Cabang Draf']);
        $draft = app(ExaminationRegistrar::class)->create([
            'patient_id' => $own->getKey(),
            'branch_id' => $draftBranch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $operator);
        app(AssessmentRecorder::class)->openFor($draft, $operator);

        // Another patient's approved report never leaks into the list.
        $this->approvedReportFor($tenant, $foreign, 'Cabang Asing');

        $this->actingAs($user)
            ->get('/patient/hasil-saya')
            ->assertOk()
            ->assertSee('Cabang Terbit')
            ->assertDontSee('Cabang Asing')
            ->assertDontSee('Cabang Draf');
    }

    public function test_the_report_stream_is_scoped_to_the_acting_patients_own_record(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Stream');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Stream');

        // BOTH versions are released, so a denial can only come from the
        // self-only identity scoping — never from the release gate.
        $ownVersion = $this->releasedReportFor($tenant, $own, 'Cabang Sendiri');
        $foreignVersion = $this->releasedReportFor($tenant, $foreign, 'Cabang Asing');

        $this->actingAs($user);

        $this->bindTenant($tenant);
        $own = $this->get(route('lba.patient.reports.download', ['report' => $ownVersion->getKey()]));
        $own->assertOk();
        $this->assertSame('application/pdf', $own->headers->get('Content-Type'));

        $this->bindTenant($tenant);
        $this->get(route('lba.patient.reports.download', ['report' => $foreignVersion->getKey()]))
            ->assertNotFound();
    }

    public function test_the_report_stream_requires_a_bound_patient_identity(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        // A patient-panel identity with a verified clinic but NO bound patient
        // record has nothing to read.
        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, null, 'Synthetic Unbound');

        $version = $this->approvedReportFor($tenant, $this->registerPatient($tenant, null, 'Synthetic Victim'), 'Cabang Lain');

        $this->actingAs($user);
        $this->bindTenant($tenant);

        $this->get(route('lba.patient.reports.download', ['report' => $version->getKey()]))
            ->assertForbidden();
    }

    // ========================================= 4. Release gate (approval != publication)

    public function test_an_approved_but_unreleased_report_stays_private(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Private Report');

        // Human approval issues the immutable version but NEVER publishes it.
        $version = $this->approvedReportFor($tenant, $own, 'Cabang Belum Terbit');

        $this->bindTenant($tenant);
        $version = $version->fresh();

        $this->assertNull($version->released_at);
        $this->assertNull($version->released_by);
        $this->assertFalse($version->isReleased());

        $this->actingAs($user);

        // Absent from the patient's own result list...
        $this->bindTenant($tenant);
        $this->get('/patient/hasil-saya')
            ->assertOk()
            ->assertDontSee('Cabang Belum Terbit');

        // ...and a direct URL to the exact (approved) version fails closed.
        $this->bindTenant($tenant);
        $this->get(route('lba.patient.reports.download', ['report' => $version->getKey()]))
            ->assertNotFound();
        $this->bindTenant($tenant);
        $this->get(route('lba.patient.reports.preview', ['report' => $version->getKey()]))
            ->assertNotFound();
    }

    public function test_an_explicit_release_enables_patient_access(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Released Report');
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);

        $version = $this->releasedReportFor($tenant, $own, 'Cabang Sudah Terbit', $reviewer);

        $this->bindTenant($tenant);
        $version = $version->fresh();

        // The release actor, the timestamp and the version are all recorded.
        $this->assertTrue($version->isReleased());
        $this->assertNotNull($version->released_at);
        $this->assertSame((string) $reviewer->getKey(), (string) $version->released_by);

        // An append-only audit event proves the moment of release.
        $event = ReportEvent::query()
            ->where('report_version_id', $version->getKey())
            ->where('action', ReportEvent::ACTION_RELEASED)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame((string) $reviewer->getKey(), (string) $event->actor_id);

        $this->actingAs($user);

        // The released report now appears in the list...
        $this->bindTenant($tenant);
        $this->get('/patient/hasil-saya')
            ->assertOk()
            ->assertSee('Cabang Sudah Terbit');

        // ...and streams to the patient as a private PDF.
        $this->bindTenant($tenant);
        $download = $this->get(route('lba.patient.reports.download', ['report' => $version->getKey()]));
        $download->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
    }

    public function test_cross_patient_and_cross_tenant_report_access_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Cross Own');
        $other = $this->registerPatient($tenant, null, 'Synthetic Cross Other');

        // Both are RELEASED, so denial can only come from identity scoping.
        $ownVersion = $this->releasedReportFor($tenant, $own, 'Cabang Sendiri');
        $otherVersion = $this->releasedReportFor($tenant, $other, 'Cabang Lain');

        // A released report of ANOTHER patient in the SAME tenant is denied.
        $this->actingAs($user);
        $this->bindTenant($tenant);
        $this->get(route('lba.patient.reports.download', ['report' => $otherVersion->getKey()]))
            ->assertNotFound();

        // The caller's own released report still streams.
        $this->bindTenant($tenant);
        $this->get(route('lba.patient.reports.download', ['report' => $ownVersion->getKey()]))
            ->assertOk();

        // A released report in ANOTHER tenant never resolves in the verified
        // tenant database, even with the exact version id.
        $foreignTenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($foreignTenant);
        $this->activate($foreignTenant);
        $this->bindTenant($foreignTenant);

        $foreignPatient = $this->registerPatient($foreignTenant, null, 'Synthetic Foreign Tenant');
        $foreignVersion = $this->releasedReportFor($foreignTenant, $foreignPatient, 'Cabang Tenant Asing');

        $this->bindTenant($tenant);
        $this->get(route('lba.patient.reports.download', ['report' => $foreignVersion->getKey()]))
            ->assertNotFound();
    }

    // ========================================= 6. Self-only orders

    public function test_my_orders_lists_only_the_acting_patients_own_orders(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        $own = $this->registerPatient($tenant, $user, 'Synthetic Own Order');
        $foreign = $this->registerPatient($tenant, null, 'Synthetic Foreign Order');
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        $branch = $this->createBranch($tenant, ['name' => 'Cabang Pesanan']);

        $product = app(ProductCatalog::class)->create([
            'name' => 'Synthetic Portal Product',
            'base_currency' => 'IDR',
            'base_price' => 15000,
            'status' => 'active',
        ]);

        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 10, $owner);

        $ownOrder = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 2],
        ], [
            'branch_id' => (string) $branch->getKey(),
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
            'patient_id' => (string) $own->getKey(),
        ], $owner);

        $foreignOrder = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => (string) $branch->getKey(),
            'currency' => 'IDR',
            'source' => Order::SOURCE_POS,
            'patient_id' => (string) $foreign->getKey(),
        ], $owner);

        $this->actingAs($user)
            ->get('/patient/pesanan-saya')
            ->assertOk()
            ->assertSee($ownOrder->order_number)
            ->assertDontSee($foreignOrder->order_number);
    }

    // ========================================= 5. Secure account linking

    public function test_an_untrusted_actor_cannot_issue_a_portal_invitation(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->bindTenant($tenant);
        $patient = $this->registerPatient($tenant, null, 'Synthetic Unauthorized Link');

        // A verified clinic identity that does NOT hold `patient.profile.manage`
        // can never mint a binding proof.
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->bindTenant($tenant);

        $this->expectException(PatientLinkException::class);

        app(PatientAccountLinker::class)->issueInvitation($patient, $reviewer);
    }

    public function test_a_forged_invitation_code_cannot_bind_an_account(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->bindTenant($tenant);
        $this->registerPatient($tenant, null, 'Synthetic Forged Code');

        $account = $this->patientUser($tenant);

        $this->bindTenant($tenant);

        // No clinic-issued proof exists, so a guessed/forged secret — or an
        // account attempting to self-link by its own email/phone — is refused.
        $this->expectException(PatientLinkException::class);

        app(PatientAccountLinker::class)->redeemInvitation($account, Str::random(64));
    }

    public function test_the_account_binding_cannot_be_assigned_through_untrusted_input(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $attacker = $this->patientUser($tenant);
        $this->bindTenant($tenant);

        // `user_id` is not mass-assignable, so a hostile payload smuggled
        // through the registrar is silently discarded — never bound.
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Mass Assignment',
            'status' => Patient::STATUS_ACTIVE,
            'user_id' => $attacker->getKey(),
        ], $owner);

        $this->bindTenant($tenant);
        $patient = $patient->fresh();

        $this->assertNull($patient->user_id);
        $this->assertFalse($patient->isLinked());
    }
}
