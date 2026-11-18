<?php

namespace Modules\KnowledgeBase\Evaluation;

use Modules\KnowledgeBase\Services\KnowledgeRetriever;
use Modules\KnowledgeBase\Support\KnowledgeRetrievalContext;

/**
 * WS9.D — the retrieval/citation evaluation harness.
 *
 * Runs the synthetic {@see KnowledgeEvaluationSuite} cases through the REAL
 * {@see KnowledgeRetriever} (the same engine SINA uses) and reports an honest
 * pass/fail per case. It deliberately reports COUNTS, never an invented accuracy
 * percentage, and it validates only the two things the engine is responsible
 * for:
 *
 *  - a GROUNDED or CONFLICT question must surface one of the expected
 *    (document, page) citations among its hits;
 *  - a NO-ANSWER question must surface NO hit (no fabricated source);
 *  - an INJECTION question must still behave like the underlying real question
 *    (retrieval is unaffected by instructions embedded in the query).
 *
 * The `documentKey => documentId` map is supplied by the caller that seeded the
 * corpus, so the harness holds no database state of its own and is trivially
 * reproducible.
 */
final class KnowledgeEvaluationHarness
{
    public function __construct(
        private readonly KnowledgeRetriever $retriever,
    ) {}

    /**
     * @param  list<array{id: string, locale: string, kind: string, question: string, represents: array<string, int>}>  $cases
     * @param  array<string, string>  $documentKeys  corpus document key => seeded document id
     * @return array{total: int, passed: int, failed: int, results: list<array{id: string, kind: string, passed: bool, question: string, expected: string, retrieved: list<array{document_id: string, page: int, score: float}>}>}
     */
    public function run(string $tenantId, array $cases, array $documentKeys): array
    {
        $results = [];
        $passed = 0;

        foreach ($cases as $case) {
            $context = KnowledgeRetrievalContext::forTenant($tenantId, ['language' => $case['locale']]);
            $hits = $this->retriever->retrieve($case['question'], $context);

            $retrieved = [];

            foreach ($hits as $hit) {
                $retrieved[] = [
                    'document_id' => (string) $hit->document->getKey(),
                    'page' => $hit->page(),
                    'score' => round($hit->score, 4),
                ];
            }

            $ok = $this->passes($case, $retrieved, $documentKeys);

            if ($ok) {
                $passed++;
            }

            $results[] = [
                'id' => $case['id'],
                'kind' => $case['kind'],
                'passed' => $ok,
                'question' => $case['question'],
                'expected' => $this->describeExpectation($case, $documentKeys),
                'retrieved' => $retrieved,
            ];
        }

        return [
            'total' => count($cases),
            'passed' => $passed,
            'failed' => count($cases) - $passed,
            'results' => $results,
        ];
    }

    /**
     * @param  array{represents: array<string, int>}  $case
     * @param  list<array{document_id: string, page: int, score: float}>  $retrieved
     * @param  array<string, string>  $documentKeys
     */
    private function passes(array $case, array $retrieved, array $documentKeys): bool
    {
        $expected = $case['represents'];

        // A no-answer case must retrieve NOTHING — a fabricated source would be
        // a hard failure.
        if ($expected === []) {
            return $retrieved === [];
        }

        foreach ($expected as $key => $page) {
            $documentId = $documentKeys[$key] ?? null;

            if ($documentId === null) {
                continue;
            }

            foreach ($retrieved as $hit) {
                if ($hit['document_id'] === $documentId && $hit['page'] === $page) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{represents: array<string, int>}  $case
     * @param  array<string, string>  $documentKeys
     */
    private function describeExpectation(array $case, array $documentKeys): string
    {
        if ($case['represents'] === []) {
            return 'no source';
        }

        $parts = [];

        foreach ($case['represents'] as $key => $page) {
            $parts[] = ($documentKeys[$key] ?? $key).'#page '.$page;
        }

        return implode(' | ', $parts);
    }
}
