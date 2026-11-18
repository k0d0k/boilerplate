<?php

namespace App\Platform\Health\Console;

use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\Tenancy\Models\TenantDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WS5 — read-only staging health and diagnostic report.
 *
 * Answers "is this deployment ready to activate a clinic?" WITHOUT mutating any
 * state and WITHOUT ever printing a credential (APP_KEY, database / mail /
 * gateway secrets) or any patient or clinical value. Every figure is derived
 * from configuration, the central governance tables and the module registry.
 *
 * It never opens a tenant clinical connection, so it can never read a patient,
 * an examination, an image or a finding. It exits non-zero when a hard check
 * fails, so it can gate a release without performing one.
 */
class StagingHealthCommand extends Command
{
    protected $signature = 'lba:staging:health
        {--json : Emit the report as machine-readable JSON}';

    protected $description = 'Read-only staging health/diagnostic report (never prints credentials or patient data).';

    public function handle(RegistersModules $modules): int
    {
        $checks = [
            ...$this->runtimeChecks(),
            ...$this->databaseChecks(),
            ...$this->tenancyChecks(),
            ...$this->moduleChecks($modules),
            ...$this->postureChecks(),
            ...$this->localizationChecks(),
        ];

        $failures = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'fail',
        ));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $failures === [],
                'failures' => count($failures),
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Check', 'Status', 'Detail'],
                array_map(
                    static fn (array $check): array => [$check['label'], strtoupper($check['status']), $check['detail']],
                    $checks,
                ),
            );

            $this->newLine();

            if ($failures === []) {
                $this->info('Staging health: OK — no blocking findings.');
            } else {
                $this->error('Staging health: '.count($failures).' blocking finding(s).');
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------- Sections

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    private function runtimeChecks(): array
    {
        $environment = (string) app()->environment();
        $debug = (bool) config('app.debug');

        return [
            $this->ok('php', 'PHP version', PHP_VERSION),
            $this->ok('laravel', 'Laravel version', app()->version()),
            $this->ok('platform', 'Platform version', (string) config('lba.platform.version', 'unknown')),
            $this->ok('environment', 'Application environment', $environment),
            $this->check(
                'debug_mode',
                'Debug mode',
                $debug ? ($environment === 'production' ? 'warn' : 'ok') : 'ok',
                $debug ? 'enabled'.($environment === 'production' ? ' (must be OFF in production)' : '') : 'disabled',
            ),
            $this->check(
                'app_key',
                'Application key',
                config('app.key') ? 'ok' : 'fail',
                config('app.key') ? 'set' : 'MISSING — run `php artisan key:generate`',
            ),
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    private function databaseChecks(): array
    {
        $connection = (string) config('database.default');

        $reachable = true;
        $detail = 'reachable';

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $reachable = false;
            $detail = 'unreachable';
        }

        $checks = [
            $this->check(
                'central_database',
                'Central database',
                $reachable ? 'ok' : 'fail',
                "connection [{$connection}] {$detail}",
            ),
        ];

        if ($reachable) {
            try {
                $applied = DB::connection()->table('migrations')->count();

                $checks[] = $this->check(
                    'central_migrations',
                    'Central migrations applied',
                    $applied > 0 ? 'ok' : 'warn',
                    (string) $applied,
                );
            } catch (Throwable) {
                $checks[] = $this->check('central_migrations', 'Central migrations applied', 'warn', 'migrations table not found');
            }
        }

        return $checks;
    }

    /**
     * Tenant readiness from the CENTRAL registry only — never a tenant connection.
     *
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    private function tenancyChecks(): array
    {
        try {
            $migrated = TenantDatabase::query()->where('status', TenantDatabase::STATUS_MIGRATED)->count();
            $pending = TenantDatabase::query()->whereIn('status', [
                TenantDatabase::STATUS_PENDING,
                TenantDatabase::STATUS_PROVISIONED,
            ])->count();
            $failed = TenantDatabase::query()->where('status', TenantDatabase::STATUS_FAILED)->count();
        } catch (Throwable) {
            return [
                $this->check('tenant_registry', 'Tenant database registry', 'warn', 'registry unavailable'),
            ];
        }

        return [
            $this->check(
                'tenant_registry',
                'Tenant databases (registry)',
                $failed > 0 ? 'warn' : 'ok',
                "migrated={$migrated} pending={$pending} failed={$failed}",
            ),
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    private function moduleChecks(RegistersModules $modules): array
    {
        $platformVersion = (string) config('lba.platform.version', '');
        $incompatible = [];

        foreach ($modules->all() as $key => $manifest) {
            if (! $manifest->isCompatibleWithPlatform($platformVersion)) {
                $incompatible[] = $key;
            }
        }

        $total = count($modules->all());

        return [
            $this->check(
                'modules',
                'Registered modules',
                $incompatible === [] ? 'ok' : 'fail',
                $incompatible === []
                    ? "{$total} compatible with platform [{$platformVersion}]"
                    : 'incompatible: '.implode(', ', $incompatible),
            ),
        ];
    }

    /**
     * Safety posture: every risky capability must stay OFF until approved.
     *
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    private function postureChecks(): array
    {
        $livePayments = (bool) config('payments.live_enabled', false);
        $selfApproval = (bool) config('lba.reports.allow_self_approval', false);

        return [
            $this->check(
                'payments_mode',
                'Payments',
                $livePayments ? 'warn' : 'ok',
                $livePayments ? 'LIVE enabled' : 'sandbox-only (fail-closed)',
            ),
            $this->check(
                'reports_separation',
                'Report separation of duties',
                $selfApproval ? 'warn' : 'ok',
                $selfApproval ? 'self-approval ALLOWED' : 'distinct reviewer required',
            ),
            $this->ok('ai_vision', 'LBA AI vision', (bool) config('lba.ai.enabled') ? 'enabled' : 'disabled'),
            $this->ok('sina', 'SINA chat', (bool) config('lba.sina.enabled') ? 'enabled' : 'disabled'),
            $this->ok('queue', 'Queue driver', (string) config('queue.default')),
            $this->ok('cache', 'Cache store', (string) config('cache.default')),
            $this->ok('mail', 'Mailer', (string) config('mail.default')),
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    private function localizationChecks(): array
    {
        try {
            $exitCode = Artisan::call('lba:localization:audit');
        } catch (Throwable) {
            return [
                $this->check('localization', 'Localization audit', 'warn', 'audit could not run'),
            ];
        }

        return [
            $this->check(
                'localization',
                'Localization audit',
                $exitCode === 0 ? 'ok' : 'fail',
                $exitCode === 0 ? 'catalogs match baseline' : 'catalog drift detected',
            ),
        ];
    }

    // -------------------------------------------------------------- Helpers

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function ok(string $key, string $label, string $detail): array
    {
        return $this->check($key, $label, 'ok', $detail);
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function check(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
