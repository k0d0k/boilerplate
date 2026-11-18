<?php

namespace Modules\KnowledgeBase\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\KnowledgeBase\Evaluation\KnowledgeEvaluationHarness;
use Modules\KnowledgeBase\Evaluation\KnowledgeEvaluationSeeder;
use Modules\KnowledgeBase\Evaluation\KnowledgeEvaluationSuite;

/**
 * WS9.D — run the synthetic retrieval/citation evaluation against a live
 * knowledge scope.
 *
 * Seeds the synthetic corpus into a dedicated `eval-{locale}` collection in the
 * CALLER-NAMED tenant scope, runs every case through the real retriever and
 * prints an honest pass/fail table (counts, never an invented accuracy figure).
 * Intended for staging UAT on `https://dev.lbacloud.com`; it never touches
 * patient data and only writes synthetic documents. Pass `--purge` to remove the
 * corpus again afterwards.
 */
final class EvaluateKnowledgeCommand extends Command
{
    protected $signature = 'lba:knowledge:evaluate
        {--tenant= : Tenant id whose knowledge scope is evaluated}
        {--actor= : Central user id or email that owns the seeded synthetic corpus}
        {--locale=* : Locales to evaluate (default: every supported locale)}
        {--purge : Remove the seeded evaluation corpus after the run}
        {--json : Emit the report as JSON}';

    protected $description = 'Run the synthetic knowledge retrieval/citation evaluation for a tenant scope.';

    public function handle(
        KnowledgeEvaluationSeeder $seeder,
        KnowledgeEvaluationHarness $harness,
    ): int {
        $tenantId = (string) $this->option('tenant');

        if ($tenantId === '') {
            $this->error('The --tenant option is required.');

            return self::FAILURE;
        }

        $actor = $this->resolveActor();

        if ($actor === null) {
            $this->error('A valid --actor (user id or email) is required to publish the synthetic corpus.');

            return self::FAILURE;
        }

        $locales = (array) ($this->option('locale') ?: KnowledgeEvaluationSuite::LOCALES);

        $report = ['tenant' => $tenantId, 'locales' => [], 'total' => 0, 'passed' => 0, 'failed' => 0];

        foreach ($locales as $locale) {
            $locale = (string) $locale;

            if (! in_array($locale, KnowledgeEvaluationSuite::LOCALES, true)) {
                $this->warn("Skipping unsupported locale [{$locale}].");

                continue;
            }

            $keys = $seeder->seed($tenantId, $locale, $actor);
            $result = $harness->run($tenantId, KnowledgeEvaluationSuite::cases($locale), $keys);

            $report['locales'][$locale] = $result;
            $report['total'] += $result['total'];
            $report['passed'] += $result['passed'];
            $report['failed'] += $result['failed'];

            if (! $this->option('json')) {
                $this->renderLocale($locale, $result);
            }

            if ($this->option('purge')) {
                $seeder->purge($tenantId, $locale);
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();
        $this->info(sprintf('Evaluation complete: %d/%d passed, %d failed.', $report['passed'], $report['total'], $report['failed']));

        return $report['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{total: int, passed: int, failed: int, results: list<array{id: string, kind: string, passed: bool, question: string, expected: string}>}  $result
     */
    private function renderLocale(string $locale, array $result): void
    {
        $this->newLine();
        $this->info(sprintf('[%s] %d/%d passed, %d failed', $locale, $result['passed'], $result['total'], $result['failed']));

        $this->table(
            ['Case', 'Kind', 'Result', 'Question', 'Expected'],
            array_map(static fn (array $case): array => [
                $case['id'],
                $case['kind'],
                $case['passed'] ? 'PASS' : 'FAIL',
                mb_strimwidth($case['question'], 0, 48, '…'),
                mb_strimwidth($case['expected'], 0, 40, '…'),
            ], $result['results']),
        );
    }

    private function resolveActor(): ?User
    {
        $actor = (string) $this->option('actor');

        if ($actor === '') {
            return null;
        }

        return User::query()
            ->where('id', $actor)
            ->orWhere('email', $actor)
            ->first();
    }
}
