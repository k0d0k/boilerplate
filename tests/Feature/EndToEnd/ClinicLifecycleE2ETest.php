<?php

namespace Tests\Feature\EndToEnd;

use App\Models\Brand;
use App\Models\PaymentGateway;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Access\StaffInvitation;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Models\AiUsageRecord;
use App\Platform\Analytics\PlatformAnalytics;
use App\Platform\Api\PatientApiTokenIssuer;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\SubscriptionLicensing;
use App\Platform\Tenancy\Models\TenantDatabase;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Commerce\Filament\Pages\PosTerminal;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Modules\Consultations\Models\AiProviderConfig;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\SinaChatService;
use Modules\Consultations\Services\SinaWalletService;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\AssessmentRecorder;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS5 Priority 3 — end-to-end clinic lifecycle (UAT).
 *
 * One integrated, deterministic pass over the twelve product workflows the
 * staging UAT must prove, wiring the real console command, services and HTTP
 * surfaces together rather than exercising any single module in isolation:
 *
 *   1. Principal creates / manages a Clinic.      7. Patient reads own released report.
 *   2. Principal invites a Clinic Admin.          8. Patient starts consented SINA chat.
 *   3. Staff activates + logs in.                 9. AI wallet usage recorded + billed.
 *   4. Clinic creates patient + appointment.     10. Clinic sells via POS.
 *   5. Practitioner finishes the LBA workflow.   11. Payment sandbox callback settles.
 *   6. Reviewer approves + releases a report.    12. Principal analytics + audit reflect it.
 *
 * Every identity, patient, image reference and product here is synthetic
 * (DECISIONS.md PRV-01); no production database is ever touched.
 */
class ClinicLifecycleE2ETest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsPrincipalGovernance, BuildsTenantDatabases, RefreshDatabase;

    private const BASE = '/api/v1/patient';

    private const NEW_PASSWORD = 'a-strong-staff-passphrase-2026';

    private const SERVER_KEY = 'SB-Mid-server-synthetic';

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

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

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

    private function activate(Tenant $tenant, string $moduleKey = 'lba'): void
    {
        if (! RegisteredModule::query()->where('module_key', $moduleKey)->exists()) {
            RegisteredModule::factory()->forKey($moduleKey)->enabled()->create();
        }

        ModuleInstallation::factory()
            ->forModule($moduleKey)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule($moduleKey)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    private function bootPanel(string $id): void
    {
        $panel = Filament::getPanel($id);
        Filament::setCurrentPanel($panel);
        $panel->boot();
    }

    /** Submit the unified staff login form the way a browser does. */
    private function submitLogin(string $email, string $password): TestResponse
    {
        $html = $this->get('/login')->assertOk()->getContent();

        if (! preg_match('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The login page did not render a Livewire snapshot.');
        }

        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'calls' => [['method' => 'authenticate', 'params' => [], 'path' => '']],
                'updates' => ['data.email' => $email, 'data.password' => $password],
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /*
    |--------------------------------------------------------------------------
    | Workflows 1–3 — Principal onboards the clinic, invites, staff activates.
    |--------------------------------------------------------------------------
    */

    public function test_workflows_1_to_3_principal_onboards_clinic_and_staff_activates(): void
    {
        // 1. Principal creates and provisions the Clinic: an unprovisioned
        //    tenant is made operational by the real setup command, which
        //    provisions + migrates the isolated database and records readiness.
        $tenant = Tenant::factory()->withoutDatabase()->create([
            'name' => 'Klinik Onboarding',
            'slug' => 'klinik-onboarding',
        ]);

        $this->artisan('lba:clinic:setup', ['--tenant' => 'klinik-onboarding'])->assertSuccessful();

        $tenantId = (string) $tenant->getKey();

        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenantId,
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        foreach (['patients', 'appointments'] as $moduleKey) {
            $this->assertDatabaseHas('module_installations', [
                'module_key' => $moduleKey,
                'scope' => ModuleScope::TENANT,
                'scope_key' => $tenantId,
                'status' => 'enabled',
            ]);

            $this->assertDatabaseHas('module_entitlements', [
                'module_key' => $moduleKey,
                'scope' => ModuleScope::TENANT,
                'scope_key' => $tenantId,
                'status' => 'granted',
            ]);
        }

        // 2. Principal invites a Clinic Admin (a NEW identity with no credential).
        $principal = $this->principal();
        $admin = User::factory()->role(RoleKey::TENANT_ADMIN)
            ->mustSetPassword()
            ->create(['email' => 'clinic.admin@example.test']);

        $membership = $this->giveMembership($admin, $tenant, [RoleKey::TENANT_ADMIN], TenantMembership::STATUS_INVITED);
        $proof = app(StaffInvitation::class)->issue($membership, $principal);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.rbac',
            'action' => StaffInvitation::ACTION_ISSUED,
            'scope_key' => $tenantId,
        ]);

        // 3. Staff activates the account through the public one-time link and
        //    signs in through the unified staff login.
        $this->post('/staff/invitation/'.$proof, [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        $admin->refresh();
        $membership->refresh();

        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->must_set_password);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->password));
        $this->assertSame(TenantMembership::STATUS_ACTIVE, $membership->status);

        $login = $this->submitLogin('clinic.admin@example.test', self::NEW_PASSWORD);
        $login->assertOk();
        $this->assertSame('/clinic', $login->json('components.0.effects.redirect'));

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.rbac',
            'action' => StaffInvitation::ACTION_REDEEMED,
            'scope_key' => $tenantId,
        ]);

        // The freshly activated Clinic Admin reaches the dashboard.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->bootPanel('clinic');

        $this->actingAs($admin->refresh())
            ->get('/clinic')
            ->assertOk()
            ->assertSee('Klinik Onboarding');
    }

    /*
    |--------------------------------------------------------------------------
    | Workflows 4–7 — clinical chain through to released patient report.
    |--------------------------------------------------------------------------
    */

    public function test_workflows_4_to_7_clinical_chain_releases_report_to_patient(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Klinis']);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, 'lba');

        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->bindTenant($tenant);

        // 4. Clinic creates a patient and an appointment.
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Pasien E2E',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $branch = $this->createBranch($tenant);
        $starts = now()->addDay()->setTime(9, 0);

        $appointment = app(AppointmentScheduler::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner);

        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);

        // 5. Practitioner completes the LBA workflow and submits for review.
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->bindTenant($tenant);

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

        app(AssessmentRecorder::class)->selectBrand(
            $assessment,
            (string) $tenant->getKey(),
            (string) $brand->getKey(),
            $operator,
        );
        app(AssessmentRecorder::class)->submitForReview($assessment->fresh(), $operator);

        // 6. A distinct reviewer approves and explicitly releases the report.
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->bindTenant($tenant);

        $version = app(AssessmentReviewer::class)->approve(
            $assessment->fresh(),
            (string) $tenant->getKey(),
            $reviewer,
        );

        $released = app(AssessmentReviewer::class)->releaseToPatient(
            $version,
            (string) $tenant->getKey(),
            $reviewer,
        );

        $this->assertNotNull($released->released_at);

        // 7. The patient reads ONLY their own released report through the API.
        $patientUser = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($patientUser, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        $this->bindTenant($tenant);
        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $owner);
        app(PatientAccountLinker::class)->redeemInvitation($patientUser, $code);

        // A second patient with their own released report must stay invisible.
        $foreignPatient = app(PatientRegistrar::class)->create([
            'full_name' => 'Pasien Asing E2E',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $foreignExamination = app(ExaminationRegistrar::class)->create([
            'patient_id' => $foreignPatient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_MANUAL,
            'record_consent' => true,
        ], $operator);

        $foreignAssessment = app(AssessmentRecorder::class)->openFor($foreignExamination, $operator);
        app(AssessmentRecorder::class)->selectBrand($foreignAssessment, (string) $tenant->getKey(), (string) $brand->getKey(), $operator);
        app(AssessmentRecorder::class)->submitForReview($foreignAssessment->fresh(), $operator);
        $foreignVersion = app(AssessmentReviewer::class)->approve($foreignAssessment->fresh(), (string) $tenant->getKey(), $reviewer);
        app(AssessmentReviewer::class)->releaseToPatient($foreignVersion, (string) $tenant->getKey(), $reviewer);

        $token = app(PatientApiTokenIssuer::class)->issue($patientUser, (string) $tenant->getKey(), 'e2e')['plain'];

        $list = $this->withToken($token)->getJson(self::BASE.'/reports')->assertOk();
        $ids = array_column($list->json('data'), 'id');

        $this->assertContains((string) $released->getKey(), $ids);
        $this->assertNotContains((string) $foreignVersion->getKey(), $ids);
        $this->assertCount(1, $ids);

        // Own released report streams; a foreign one is indistinguishable from missing.
        $this->withToken($token)->get(self::BASE.'/reports/'.$released->getKey().'/download')->assertOk();
        $this->withToken($token)->getJson(self::BASE.'/reports/'.$foreignVersion->getKey().'/download')->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Workflows 8–9 — consented SINA chat + AI wallet billing.
    |--------------------------------------------------------------------------
    */

    public function test_workflows_8_to_9_sina_consultation_and_ai_wallet_billing(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik SINA']);
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $tenantId = (string) $tenant->getKey();

        $branch = $this->createBranch($tenant);
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Pasien SINA E2E',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $consultation = app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => Consultation::CHANNEL_SINA_CHAT,
            'type' => Consultation::TYPE_CONSULTATION,
        ], $owner);

        // 8. The patient has given explicit AI-assistance consent, so a
        //    consented SINA turn is answered and billed.
        PatientConsent::query()->create([
            'patient_id' => $patient->getKey(),
            'consent_type' => PatientConsent::TYPE_AI_ASSISTANCE,
            'version' => '1',
            'status' => PatientConsent::STATUS_GRANTED,
            'recorded_by' => $owner->getKey(),
            'recorded_at' => now(),
        ]);

        app(SinaWalletService::class)->grant($tenantId, (string) $patient->getKey(), 100, 'e2e seed', 'e2e-sina-'.Str::ulid());

        AiProviderConfig::query()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'label' => 'E2E SINA endpoint',
            'base_url' => 'https://sina.test/v1',
            'model' => 'test-sina-model',
            'is_default' => true,
            'enabled' => true,
        ]);

        Http::fake([
            'sina.test/*' => Http::response([
                'model' => 'test-sina-model',
                'choices' => [['message' => ['content' => 'Halo, saya SINA.']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
            ], 200),
        ]);

        $reply = app(SinaChatService::class)->sendPatientMessage($consultation, $patient, 'Apa itu LBA?', $owner);

        $this->assertSame('Halo, saya SINA.', $reply->content);

        $this->bindTenant($tenant);
        $this->assertSame(99, app(SinaWalletService::class)->balanceFor($tenantId, (string) $patient->getKey()));

        // 9. Platform AI wallet usage is recorded and billed (reserve → capture).
        $wallet = app(AiWalletService::class);
        $job = 'analysis-'.Str::ulid();

        $wallet->grant($tenantId, 100, 'e2e seed', 'e2e-grant-'.Str::ulid());
        $wallet->reserve($tenantId, $job, 30, 'e2e-reserve-'.$job);
        $wallet->capture($tenantId, $job, 30, 'e2e-capture-'.$job, [
            'provider' => 'openai_compatible',
            'model_name' => 'test-vision-model',
            'unit_price_credits' => 30,
        ]);

        $stored = $wallet->walletFor($tenantId);

        $this->assertSame(70, (int) $stored->available_credits);
        $this->assertSame(0, (int) $stored->reserved_credits);
        $this->assertSame(30, (int) $stored->lifetime_consumed_credits);
        $this->assertSame(1, AiUsageRecord::query()->where('tenant_id', $tenantId)->count());

        // Analytics report the wallet without exposing any clinical detail.
        $this->assertSame(
            ['available' => 70, 'reserved' => 0, 'consumed' => 30],
            app(PlatformAnalytics::class)->aiCredits(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Workflows 10–11 — POS sale + payment sandbox callback.
    |--------------------------------------------------------------------------
    */

    public function test_workflows_10_to_11_pos_sale_and_payment_callback_settles(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Kasir']);
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, 'commerce');

        $branch = $this->createBranch($tenant);
        $product = app(ProductCatalog::class)->create([
            'name' => 'Produk Kasir E2E',
            'base_currency' => 'IDR',
            'base_price' => 25000,
        ]);
        app(InventoryService::class)->receive($product, (string) $branch->getKey(), 10, $owner);

        // 10. The cashier settles a POS sale: a real order is written and stock reserved.
        $this->actingAs($owner);
        $this->bindTenant($tenant);

        $page = new PosTerminal;
        $page->branchId = (string) $branch->getKey();
        $page->currency = 'IDR';
        $page->addProduct((string) $product->getKey(), 2);
        $page->checkout();

        $this->bindTenant($tenant);

        $posOrder = Order::query()->firstOrFail();
        $this->assertSame(Order::SOURCE_POS, $posOrder->source);
        $this->assertTrue($posOrder->isPaid());
        $this->assertSame(8, app(InventoryService::class)->level($product, (string) $branch->getKey()));

        // 11. An online order paid through the sandbox gateway is settled by the
        //     signature-verified webhook, exactly once even when replayed.
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        PaymentGateway::factory()
            ->provider('midtrans')
            ->enabled()
            ->credentials(['server_key' => self::SERVER_KEY, 'client_key' => 'SB-Mid-client-synthetic'])
            ->create();

        $this->bindTenant($tenant);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'quantity' => 1],
        ], [
            'branch_id' => (string) $branch->getKey(),
            'currency' => 'IDR',
            'source' => Order::SOURCE_ONLINE,
        ], $owner);

        app(PaymentManager::class)->charge($order, 'midtrans');

        $this->tenantDatabases()->forget();

        $payload = [
            'order_id' => (string) $order->order_number,
            'status_code' => '200',
            'gross_amount' => '25000.00',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY,
        );

        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();

        $this->bindTenant($tenant);

        $this->assertTrue(Order::query()->findOrFail($order->getKey())->isPaid());

        // Replay: still settled once.
        $this->tenantDatabases()->forget();
        $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();
        $this->bindTenant($tenant);

        $this->assertSame('25000.00', (string) Order::query()->findOrFail($order->getKey())->paid_total);
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow 12 — Principal analytics and audit reflect real activity.
    |--------------------------------------------------------------------------
    */

    public function test_workflow_12_principal_analytics_and_audit_reflect_activity(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Analitik', 'status' => Tenant::STATUS_ACTIVE]);
        $this->provisionTenantDatabase($tenant);

        // Real provisioning activity: the setup command provisions, activates
        // and audits the module activation.
        $this->artisan('lba:clinic:setup', ['--tenant' => $tenant->slug])->assertSuccessful();

        $tenantId = (string) $tenant->getKey();

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'patients',
            'action' => 'activate',
            'result' => 'allowed',
            'scope_key' => $tenantId,
        ]);

        // A live subscription feeds the principal revenue analytics.
        $plan = SubscriptionPlan::query()->create([
            'code' => 'plan-e2e',
            'name' => 'Klinik IDR Plan',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => '250000.00',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 0,
            'module_keys' => [],
        ]);
        app(SubscriptionLicensing::class)->subscribe($tenant, $plan);

        $analytics = app(PlatformAnalytics::class);

        $this->assertSame(['total' => 1, 'active' => 1, 'suspended' => 0], $analytics->partners());
        $this->assertSame(['IDR' => 250000.0], $analytics->monthlyRecurringRevenue());

        // The Principal panel renders the real analytics and audit surfaces.
        $this->bootPanel('principal');

        $this->actingAs($this->principal())
            ->get('/principal/analytics')
            ->assertOk()
            ->assertSee('Klinik IDR Plan');

        $this->actingAs($this->principal())
            ->get('/principal/audit-logs')
            ->assertOk()
            ->assertSee('activate');
    }
}
