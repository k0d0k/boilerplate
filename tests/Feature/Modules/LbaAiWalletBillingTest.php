<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Models\AiPricingRule;
use App\Platform\AiBilling\Models\AiUsageRecord;
use App\Platform\AiBilling\Models\TenantAiWallet;
use App\Platform\AiBilling\Models\TenantAiWalletLedgerEntry;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\LBA\AI\Billing\AiCreditReconciler;
use Modules\LBA\AI\Contracts\VisionAnalyzer;
use Modules\LBA\AI\Dto\VisionRequest;
use Modules\LBA\AI\Dto\VisionResult;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\AI\Jobs\AnalyzeExaminationImage;
use Modules\LBA\AI\Providers\MockVisionAnalyzer;
use Modules\LBA\AI\Services\AiAnalysisService;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Detection;
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
 * Phase 6 — tenant AI credit wallet + usage billing (focused essentials).
 *
 * Covers ONLY the ten required behaviours: cross-tenant wallet isolation,
 * reserve/capture/release accounting, insufficient balance, no overspend,
 * retry idempotency, reconciliation of an interrupted cross-database
 * operation, zero-cost manual/synthetic inference and the production billing
 * safeguards. Every identity, patient and image here is synthetic.
 */
class LbaAiWalletBillingTest extends TestCase
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

        // A stable model identity so a pricing rule can match the job snapshot.
        config()->set('lba.ai.model.name', 'test-vision-model');
        config()->set('lba.ai.model.version', '1');
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
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithOwner(string $roleKey = RoleKey::TENANT_OWNER): array
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, $roleKey);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        return [$tenant, $owner];
    }

    /**
     * Enable billing and register an explicit per-job price for the test
     * provider/model. The price is never invented — it always comes from a rule.
     */
    private function enableBilling(int $price): void
    {
        config()->set('lba.ai.enabled', true);
        config()->set('lba.ai.billing.enabled', true);

        AiPricingRule::query()->create([
            'provider' => 'test-vision',
            'model_name' => (string) config('lba.ai.model.name'),
            'model_version' => (string) config('lba.ai.model.version'),
            'unit' => AiPricingRule::UNIT_PER_JOB,
            'unit_price_credits' => $price,
            'active' => true,
        ]);
    }

    /**
     * Bind a non-synthetic VisionAnalyzer so inference is billable, with a
     * controllable failure switch.
     */
    private function bindAnalyzer(bool $fail = false): object
    {
        $analyzer = new class($fail) implements VisionAnalyzer
        {
            public function __construct(public bool $fail) {}

            public function provider(): string
            {
                return 'test-vision';
            }

            public function analyze(VisionRequest $request): VisionResult
            {
                if ($this->fail) {
                    throw AiAnalysisException::gatewayUnavailable();
                }

                return new VisionResult(
                    protocolVersion: '1',
                    provider: 'test-vision',
                    modelName: (string) config('lba.ai.model.name'),
                    modelVersion: (string) config('lba.ai.model.version'),
                    synthetic: false,
                    detections: [[
                        'label' => 'synthetic_observation',
                        'geometry' => ['x' => 0.1, 'y' => 0.2],
                        'confidence' => 0.9,
                        'quality_flags' => ['not_a_diagnosis'],
                    ]],
                );
            }
        };

        $this->app->instance(VisionAnalyzer::class, $analyzer);

        return $analyzer;
    }

    private function grantCredits(Tenant $tenant, int $credits): void
    {
        app(AiWalletService::class)->grant(
            (string) $tenant->getKey(),
            $credits,
            'synthetic test balance',
            'lba-ai:grant:'.Str::ulid(),
        );
    }

    private function ensureWallet(Tenant $tenant): void
    {
        app(AiWalletService::class)->ensureWallet((string) $tenant->getKey());
    }

    private function wallet(Tenant $tenant): TenantAiWallet
    {
        return TenantAiWallet::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
    }

    /**
     * @return Collection<int, TenantAiWalletLedgerEntry>
     */
    private function ledger(Tenant $tenant): Collection
    {
        return TenantAiWalletLedgerEntry::query()->where('tenant_id', $tenant->getKey())->get();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeExamination(Tenant $tenant, User $owner, array $overrides = []): Examination
    {
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Wallet Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        return app(ExaminationRegistrar::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'mode' => Examination::MODE_AI,
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

    private function service(): AiAnalysisService
    {
        return app(AiAnalysisService::class);
    }

    // ============================================ 1. Cross-tenant wallet isolation

    public function test_tenant_a_can_never_spend_tenant_b_wallet(): void
    {
        $this->enableBilling(30);

        [$tenantA] = $this->tenantWithOwner();
        [$tenantB, $ownerB] = $this->tenantWithOwner();

        $this->grantCredits($tenantA, 100);
        $this->ensureWallet($tenantB); // tenant B has an empty, active wallet

        $this->bindAnalyzer();
        Queue::fake();

        $examination = $this->makeExamination($tenantB, $ownerB);
        $image = $this->storeImage($examination, $ownerB);
        $this->bindTenant($tenantB);

        try {
            $this->service()->startAnalysis($examination, $image, $ownerB);
            $this->fail('Tenant B must not be able to bill against tenant A credits.');
        } catch (AiAnalysisException $exception) {
            $this->assertSame('insufficient_credits', $exception->errorCode());
        }

        Queue::assertNothingPushed();

        // Tenant A's credits are never touched; tenant B stays empty.
        $this->assertSame(100, $this->wallet($tenantA)->available_credits);
        $this->assertSame(0, $this->wallet($tenantB)->available_credits);
        $this->assertSame(0, $this->wallet($tenantB)->reserved_credits);

        $this->bindTenant($tenantB);
        $this->assertSame(0, AnalysisJob::query()->count());
    }

    // ============================================ 2. Reserve accounting

    public function test_a_reservation_moves_credits_from_available_to_reserved(): void
    {
        $this->enableBilling(30);
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->grantCredits($tenant, 100);
        $this->bindAnalyzer();

        Queue::fake();

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $job->refresh();

        $this->assertTrue($job->isBillable());
        $this->assertSame(AnalysisJob::CREDITS_RESERVED, $job->credits_state);
        $this->assertSame(30, $job->unit_price_credits);
        $this->assertNotNull($job->pricing_rule_ref);

        $wallet = $this->wallet($tenant);
        $this->assertSame(70, $wallet->available_credits);
        $this->assertSame(30, $wallet->reserved_credits);

        $this->assertSame(1, $this->ledger($tenant)->where('action', 'reserve')->count());
        $this->assertCount(1, Queue::pushed(AnalyzeExaminationImage::class));
    }

    // ============================================ 3. Capture charges once

    public function test_a_completed_job_captures_credits_exactly_once(): void
    {
        $this->enableBilling(30);
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->grantCredits($tenant, 100);
        $this->bindAnalyzer();

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        // Sync queue: reservation then inline completion then capture.
        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $job->refresh();

        $this->assertSame(AnalysisJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(AnalysisJob::CREDITS_CAPTURED, $job->credits_state);

        $wallet = $this->wallet($tenant);
        $this->assertSame(70, $wallet->available_credits);
        $this->assertSame(0, $wallet->reserved_credits);
        $this->assertSame(30, $wallet->lifetime_consumed_credits);

        $this->assertSame(1, $this->ledger($tenant)->where('action', 'capture')->count());
        $this->assertSame(1, AiUsageRecord::query()->where('tenant_id', $tenant->getKey())->count());

        // Re-completing the same job never double-charges.
        $fresh = AnalysisJob::query()->whereKey($job->getKey())->firstOrFail();
        $result = app(VisionAnalyzer::class)->analyze(new VisionRequest(
            jobReference: (string) $fresh->request_ref,
            imageReference: (string) $image->getKey(),
            contents: (string) Storage::disk('lba')->get((string) $image->object_key),
            checksum: (string) $image->checksum,
            mimeType: (string) $image->mime_type,
            protocolVersion: '1',
        ));

        $this->service()->completeAnalysis($fresh, $result);

        $this->bindTenant($tenant);
        $this->assertSame(70, $this->wallet($tenant)->available_credits);
        $this->assertSame(30, $this->wallet($tenant)->lifetime_consumed_credits);
        $this->assertSame(1, $this->ledger($tenant)->where('action', 'capture')->count());
        $this->assertSame(1, AiUsageRecord::query()->where('tenant_id', $tenant->getKey())->count());
        $this->assertSame(1, Detection::query()->count());
    }

    // ============================================ 4. Release refunds once

    public function test_a_failed_job_releases_the_reservation_exactly_once(): void
    {
        $this->enableBilling(30);
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->grantCredits($tenant, 100);
        $this->bindAnalyzer(fail: true);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $job->refresh();

        $this->assertSame(AnalysisJob::STATUS_FAILED, $job->status);
        $this->assertSame(AnalysisJob::CREDITS_RELEASED, $job->credits_state);

        $wallet = $this->wallet($tenant);
        $this->assertSame(100, $wallet->available_credits);
        $this->assertSame(0, $wallet->reserved_credits);
        $this->assertSame(0, $wallet->lifetime_consumed_credits);

        $this->assertSame(1, $this->ledger($tenant)->where('action', 'release')->count());

        // A repeated failure/release never double-refunds.
        $this->service()->failAnalysis($job, 'gateway_unavailable');

        $this->bindTenant($tenant);
        $this->assertSame(100, $this->wallet($tenant)->available_credits);
        $this->assertSame(0, $this->wallet($tenant)->reserved_credits);
        $this->assertSame(1, $this->ledger($tenant)->where('action', 'release')->count());
    }

    // ============================================ 5. Insufficient balance denies dispatch

    public function test_insufficient_balance_denies_a_billable_dispatch(): void
    {
        $this->enableBilling(30);
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->grantCredits($tenant, 10); // less than the 30-credit price
        $this->bindAnalyzer();

        Queue::fake();

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        try {
            $this->service()->startAnalysis($examination, $image, $owner);
            $this->fail('A billable dispatch without balance must be denied.');
        } catch (AiAnalysisException $exception) {
            $this->assertSame('insufficient_credits', $exception->errorCode());
        }

        Queue::assertNothingPushed();

        $this->bindTenant($tenant);
        $this->assertSame(0, AnalysisJob::query()->count());

        $wallet = $this->wallet($tenant);
        $this->assertSame(10, $wallet->available_credits);
        $this->assertSame(0, $wallet->reserved_credits);
        $this->assertSame(0, $this->ledger($tenant)->where('action', 'reserve')->count());
    }

    // ============================================ 6. No overspend

    public function test_concurrent_reservations_cannot_overspend_the_wallet(): void
    {
        $this->enableBilling(30);
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->grantCredits($tenant, 30); // exactly one job's worth
        $this->bindAnalyzer();

        Queue::fake();

        // The first job reserves the whole balance.
        $examOne = $this->makeExamination($tenant, $owner);
        $imageOne = $this->storeImage($examOne, $owner);
        $this->bindTenant($tenant);
        $this->service()->startAnalysis($examOne, $imageOne, $owner);

        // A second, separate job cannot reserve what is no longer available.
        $examTwo = $this->makeExamination($tenant, $owner);
        $imageTwo = $this->storeImage($examTwo, $owner);
        $this->bindTenant($tenant);

        try {
            $this->service()->startAnalysis($examTwo, $imageTwo, $owner);
            $this->fail('A second reservation must not overspend the wallet.');
        } catch (AiAnalysisException $exception) {
            $this->assertSame('insufficient_credits', $exception->errorCode());
        }

        $wallet = $this->wallet($tenant);
        $this->assertSame(0, $wallet->available_credits);
        $this->assertSame(30, $wallet->reserved_credits);
        $this->assertSame(1, $this->ledger($tenant)->where('action', 'reserve')->count());

        $this->bindTenant($tenant);
        $this->assertSame(1, AnalysisJob::query()->count());
        $this->assertCount(1, Queue::pushed(AnalyzeExaminationImage::class));
    }

    // ============================================ 7. Retry never double-charges

    public function test_a_retry_after_release_re_reserves_and_charges_at_most_once(): void
    {
        $this->enableBilling(30);
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->grantCredits($tenant, 100);
        $analyzer = $this->bindAnalyzer(fail: true);

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        // First attempt fails inline -> reservation released.
        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $job->refresh();
        $this->assertSame(AnalysisJob::STATUS_FAILED, $job->status);
        $this->assertSame(100, $this->wallet($tenant)->available_credits);

        // The gateway recovers; a retry re-reserves safely and completes.
        $analyzer->fail = false;
        $retried = $this->service()->retryAnalysis($job, $owner);

        $this->bindTenant($tenant);
        $retried->refresh();

        $this->assertSame(AnalysisJob::STATUS_COMPLETED, $retried->status);
        $this->assertSame(AnalysisJob::CREDITS_CAPTURED, $retried->credits_state);

        $wallet = $this->wallet($tenant);
        $this->assertSame(70, $wallet->available_credits);
        $this->assertSame(0, $wallet->reserved_credits);
        $this->assertSame(30, $wallet->lifetime_consumed_credits); // charged ONCE
        $this->assertSame(1, $this->ledger($tenant)->where('action', 'capture')->count());
        $this->assertSame(1, AiUsageRecord::query()->where('tenant_id', $tenant->getKey())->count());
        $this->assertSame(2, $this->ledger($tenant)->where('action', 'reserve')->count());
    }

    // ============================================ 8. Reconciliation

    public function test_an_interrupted_cross_database_operation_is_reconcilable(): void
    {
        $this->enableBilling(30);
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->grantCredits($tenant, 100);
        $this->bindAnalyzer();

        Queue::fake();

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $job = $this->service()->startAnalysis($examination, $image, $owner);

        // Crash simulation: the result reached the tenant database, but the
        // capture never reached the central ledger (state left 'reserved').
        $this->bindTenant($tenant);
        AnalysisJob::query()->whereKey($job->getKey())->update([
            'status' => AnalysisJob::STATUS_COMPLETED,
            'credits_state' => AnalysisJob::CREDITS_RESERVED,
        ]);

        // A second, stranded reservation whose tenant row never survived.
        app(AiWalletService::class)->reserve(
            (string) $tenant->getKey(),
            'orphan-ref',
            5,
            'lba-ai:reserve:orphan-ref:1',
        );
        TenantAiWalletLedgerEntry::query()
            ->where('idempotency_key', 'lba-ai:reserve:orphan-ref:1')
            ->update(['created_at' => now()->subMinutes(60)]);

        $summary = app(AiCreditReconciler::class)->reconcileTenant((string) $tenant->getKey());

        $this->assertSame(1, $summary['captured']);
        $this->assertSame(1, $summary['orphans']);

        $wallet = $this->wallet($tenant);
        $this->assertSame(70, $wallet->available_credits);
        $this->assertSame(0, $wallet->reserved_credits);
        $this->assertSame(30, $wallet->lifetime_consumed_credits);

        $this->bindTenant($tenant);
        $this->assertSame(
            AnalysisJob::CREDITS_CAPTURED,
            AnalysisJob::query()->whereKey($job->getKey())->value('credits_state'),
        );

        // Re-running reconciliation is a no-op (nothing is settled twice).
        $again = app(AiCreditReconciler::class)->reconcileTenant((string) $tenant->getKey());
        $this->assertSame(0, $again['captured']);
        $this->assertSame(0, $again['orphans']);
        $this->assertSame(30, $this->wallet($tenant)->lifetime_consumed_credits);
    }

    // ============================================ 9. Manual + synthetic cost zero

    public function test_manual_and_synthetic_mock_analyses_always_cost_zero(): void
    {
        $this->enableBilling(30);
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->grantCredits($tenant, 100);

        // (a) Synthetic/mock inference stays non-billable even with billing on.
        $this->app->instance(VisionAnalyzer::class, $this->app->make(MockVisionAnalyzer::class));

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        $job = $this->service()->startAnalysis($examination, $image, $owner);

        $this->bindTenant($tenant);
        $job->refresh();

        $this->assertSame('mock', $job->provider);
        $this->assertFalse($job->isBillable());
        $this->assertNull($job->credits_state);

        // (b) Manual mode never enters the AI pipeline at all.
        $manual = $this->makeExamination($tenant, $owner, ['mode' => Examination::MODE_MANUAL]);
        $manualImage = $this->storeImage($manual, $owner);
        $this->bindTenant($tenant);

        try {
            $this->service()->startAnalysis($manual, $manualImage, $owner);
            $this->fail('Manual mode must not run AI analysis.');
        } catch (AiAnalysisException $exception) {
            $this->assertSame('mode_not_processable', $exception->errorCode());
        }

        $wallet = $this->wallet($tenant);
        $this->assertSame(100, $wallet->available_credits);
        $this->assertSame(0, $wallet->reserved_credits);
        $this->assertSame(0, $wallet->lifetime_consumed_credits);
        $this->assertSame(0, $this->ledger($tenant)->whereIn('action', ['reserve', 'capture'])->count());
    }

    // ============================================ 10. Production safeguards

    public function test_production_mock_and_a_stray_sandbox_flag_cannot_bypass_billing_safeguards(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();

        // A stray sandbox flag must NOT enable AI in production.
        config()->set('lba.ai.enabled', false);
        config()->set('lba.ai.sandbox', true);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertFalse($this->service()->aiEnabled());

        $examination = $this->makeExamination($tenant, $owner);
        $image = $this->storeImage($examination, $owner);
        $this->bindTenant($tenant);

        try {
            $this->service()->startAnalysis($examination, $image, $owner);
            $this->fail('Sandbox must not enable AI in production.');
        } catch (AiAnalysisException $exception) {
            $this->assertSame('ai_disabled', $exception->errorCode());
        }

        // Even with AI explicitly enabled, a mock provider is prohibited, so no
        // billable inference can ever slip through.
        config()->set('lba.ai.enabled', true);

        try {
            $this->service()->startAnalysis($examination->fresh(), $image, $owner);
            $this->fail('A mock provider must be prohibited in production.');
        } catch (AiAnalysisException $exception) {
            $this->assertSame('production_mock_prohibited', $exception->errorCode());
        }

        $this->bindTenant($tenant);
        $this->assertSame(0, AnalysisJob::query()->count());
    }
}
