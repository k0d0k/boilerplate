<?php

namespace Modules\LBA\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\LBA\AI\Billing\WalletAiCreditReserver;
use Modules\LBA\AI\Contracts\ReservesAiCredits;
use Modules\LBA\AI\Contracts\VisionAnalyzer;
use Modules\LBA\AI\Providers\FastApiVisionAnalyzer;
use Modules\LBA\AI\Providers\MockVisionAnalyzer;
use Modules\LBA\AI\Support\NullAiCreditReserver;
use Modules\LBA\Console\ImportLegacyIndicatorCatalogCommand;
use Modules\LBA\Console\ProvisionAiWalletCreditsCommand;
use Modules\LBA\Console\ReconcileAiCreditsCommand;
use Modules\LBA\Http\Controllers\ImageStreamController;
use Modules\LBA\Http\Controllers\IngestController;
use Modules\LBA\Http\Controllers\PatientReportStreamController;
use Modules\LBA\Http\Controllers\ReportStreamController;
use Modules\LBA\Ingest\IngestCredentialContext;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Phase 4/5 — LBA module service provider.
 *
 * Besides the base module wiring (translations `lba::…`, views `lba::…`) the
 * provider registers ONE application route: the authenticated, tenant-scoped
 * microscopy image streaming endpoint. It is the only way an original image is
 * ever read back — there is no public path, no public disk and no signed URL.
 *
 * Phase 5 adds the AI vision bindings: the {@see VisionAnalyzer} adapter is
 * selected from configuration (the synthetic mock or the FastAPI gateway).
 *
 * Phase 6 replaces the documented {@see NullAiCreditReserver}
 * no-op with the real {@see WalletAiCreditReserver}, and registers the
 * controlled credit-provisioning and reconciliation commands.
 *
 * The tenant tables live in the shared tenant migration path
 * (`database/migrations/tenant`), so the module ships no migrations of its own.
 */
class LbaServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'LBA';

    protected string $nameLower = 'lba';

    /**
     * @var list<class-string>
     */
    protected array $commands = [
        ProvisionAiWalletCreditsCommand::class,
        ReconcileAiCreditsCommand::class,
        ImportLegacyIndicatorCatalogCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(VisionAnalyzer::class, function (): VisionAnalyzer {
            return match ((string) config('lba.ai.provider', 'mock')) {
                'fastapi' => $this->app->make(FastApiVisionAnalyzer::class),
                default => $this->app->make(MockVisionAnalyzer::class),
            };
        });

        // Phase 6 — real, tenant-scoped, idempotent AI credit wallet.
        $this->app->bind(ReservesAiCredits::class, WalletAiCreditReserver::class);

        // WS6.B — a fresh agent-ingest credential context per request; it can
        // never leak one caller's scope into another.
        $this->app->scoped(IngestCredentialContext::class, fn (): IngestCredentialContext => new IngestCredentialContext);
    }

    public function boot(): void
    {
        parent::boot();

        Route::middleware(['web', 'auth'])
            ->get('lba/images/{image}', ImageStreamController::class)
            ->name('lba.images.stream');

        // Phase 7B — the ONLY way a generated report is read back: an
        // authenticated, tenant- and branch-scoped private stream. There is no
        // public path and no signed URL.
        Route::middleware(['web', 'auth'])
            ->get('lba/reports/{report}', [ReportStreamController::class, 'preview'])
            ->name('lba.reports.preview');

        Route::middleware(['web', 'auth'])
            ->get('lba/reports/{report}/download', [ReportStreamController::class, 'download'])
            ->name('lba.reports.download');

        // Phase 8A — the patient-facing surface for the SAME private reports.
        // A patient reads back ONLY their own approved reports; authorization
        // is enforced entirely on the server (see the controller).
        Route::middleware(['web', 'auth'])
            ->get('lba/patient/reports/{report}', [PatientReportStreamController::class, 'preview'])
            ->name('lba.patient.reports.preview');

        Route::middleware(['web', 'auth'])
            ->get('lba/patient/reports/{report}/download', [PatientReportStreamController::class, 'download'])
            ->name('lba.patient.reports.download');

        // WS6.B — the scoped agent ingest endpoint. It is NOT public: every
        // request must present a currently-usable, scoped credential (bound to
        // one tenant/branch/operator and one editable examination). The
        // credential determines the tenant database and the target examination —
        // never client input. The `api` group keeps the request sessionless (the
        // agent has no CSRF token); `lba.ingest` authenticates and binds the
        // tenant; `throttle:lba-ingest` bounds the rate.
        //
        // The throttle middleware is ordered ahead of the authentication
        // middleware by the framework's middleware priority, so the credential
        // is not yet resolved here. The limiter therefore keys on the SHA-256 of
        // the presented bearer secret (per credential, order-independent) and
        // falls back to the caller's IP when no secret is presented — which also
        // blunts brute-forcing of secrets before authentication even runs.
        RateLimiter::for('lba-ingest', function (Request $request): Limit {
            $token = $request->bearerToken();

            $key = $token !== null && $token !== ''
                ? 'lba-ingest:'.hash('sha256', $token)
                : 'lba-ingest:ip:'.$request->ip();

            return Limit::perMinute((int) config('lba.ingest.rate_per_minute', 30))->by($key);
        });

        Route::middleware(['api', 'lba.ingest', 'throttle:lba-ingest'])
            ->post('api/lba/ingest', IngestController::class)
            ->name('lba.ingest');
    }
}
