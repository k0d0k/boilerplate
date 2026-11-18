<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Evaluation\KnowledgeEvaluationHarness;
use Modules\KnowledgeBase\Evaluation\KnowledgeEvaluationSeeder;
use Modules\KnowledgeBase\Evaluation\KnowledgeEvaluationSuite;
use Modules\KnowledgeBase\Services\KnowledgeOperations;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Workstream 9 (WS9.D) — the synthetic retrieval/citation evaluation.
 *
 * Runs the shipped {@see KnowledgeEvaluationSuite} through the REAL retriever in
 * each supported locale (id / en / ms-MY) and asserts an HONEST count: at least
 * twenty prompts per locale and ZERO failures across the grounded, no-answer,
 * conflicting-source and prompt-injection modes. The corpus is entirely
 * synthetic and non-patient, so no real article, book or patient record is used.
 *
 * The harness reports pass/fail per case; it deliberately never invents an
 * accuracy percentage.
 */
class KnowledgeBaseEvaluationTest extends TestCase
{
    use BuildsAccess, BuildsTenantDatabases, RefreshDatabase;

    private const DISK = 'lba-knowledge';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Storage::fake(self::DISK);

        config([
            'lba.knowledge.enabled' => true,
            'lba.knowledge.embedding.provider' => 'none',
        ]);
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    /**
     * @return array{0: string, 1: User}
     */
    private function scope(): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        return [(string) $tenant->getKey(), $actor];
    }

    public function test_every_locale_has_at_least_twenty_prompts_and_zero_failures(): void
    {
        [$tenantId, $actor] = $this->scope();

        $seeder = app(KnowledgeEvaluationSeeder::class);
        $harness = app(KnowledgeEvaluationHarness::class);

        foreach (KnowledgeEvaluationSuite::LOCALES as $locale) {
            $cases = KnowledgeEvaluationSuite::cases($locale);

            $this->assertGreaterThanOrEqual(
                20,
                count($cases),
                "Locale [{$locale}] must define at least twenty evaluation prompts.",
            );

            $documentKeys = $seeder->seed($tenantId, $locale, $actor);
            $report = $harness->run($tenantId, $cases, $documentKeys);

            $failures = array_values(array_filter(
                $report['results'],
                static fn (array $result): bool => $result['passed'] === false,
            ));

            $this->assertSame(
                0,
                $report['failed'],
                sprintf(
                    "Locale [%s] failed %d/%d cases:\n%s",
                    $locale,
                    $report['failed'],
                    $report['total'],
                    (string) json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ),
            );

            $this->assertSame(count($cases), $report['total']);
        }
    }

    public function test_the_suite_covers_grounded_no_answer_conflict_and_injection_modes(): void
    {
        foreach (KnowledgeEvaluationSuite::LOCALES as $locale) {
            $kinds = array_values(array_unique(array_map(
                static fn (array $case): string => $case['kind'],
                KnowledgeEvaluationSuite::cases($locale),
            )));

            sort($kinds);

            $this->assertSame(
                ['conflict', 'grounded', 'injection', 'none'],
                $kinds,
                "Locale [{$locale}] must exercise every required evaluation mode.",
            );
        }
    }

    public function test_the_evaluation_corpus_is_never_retrievable_by_another_tenant(): void
    {
        [$ownerTenant, $actor] = $this->scope();
        [$otherTenant] = $this->scope();

        $seeder = app(KnowledgeEvaluationSeeder::class);
        $harness = app(KnowledgeEvaluationHarness::class);

        $documentKeys = $seeder->seed($ownerTenant, 'id', $actor);
        $this->assertNotEmpty($documentKeys);

        // Tenant B runs the SAME grounded cases: because the corpus lives only
        // in tenant A's scope, every grounded question must retrieve nothing.
        $grounded = array_values(array_filter(
            KnowledgeEvaluationSuite::cases('id'),
            static fn (array $case): bool => $case['kind'] === 'grounded',
        ));

        $report = $harness->run($otherTenant, $grounded, $documentKeys);

        foreach ($report['results'] as $result) {
            $this->assertSame(
                [],
                $result['retrieved'],
                'Another tenant must never retrieve a scope it does not own.',
            );
        }
    }

    public function test_purging_the_evaluation_corpus_removes_it_entirely(): void
    {
        [$tenantId, $actor] = $this->scope();

        $seeder = app(KnowledgeEvaluationSeeder::class);

        $seeder->seed($tenantId, 'en', $actor);

        $this->assertNotEmpty(app(KnowledgeOperations::class)->probe($tenantId, 'hypertension', 'en'));

        $seeder->purge($tenantId, 'en');

        $this->assertSame([], app(KnowledgeOperations::class)->probe($tenantId, 'hypertension', 'en'));
    }
}
