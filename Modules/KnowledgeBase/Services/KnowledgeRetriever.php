<?php

namespace Modules\KnowledgeBase\Services;

use Modules\KnowledgeBase\Models\KnowledgeChunk;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Support\KnowledgeHit;
use Modules\KnowledgeBase\Support\KnowledgeRetrievalContext;
use Modules\KnowledgeBase\Support\KnowledgeScope;
use Throwable;

/**
 * WS9.B — the tenant-safe retrieval engine.
 *
 * Candidates are restricted to chunks whose document is PUBLISHED and whose
 * version is the document's CURRENT version, AND whose scope is either the
 * acting tenant's own scope or (when enabled) the globally published platform
 * scope. The tenant is taken from the trusted {@see KnowledgeRetrievalContext}
 * — never from the query string — so no client can request another tenant's
 * documents and no `/search?tenant_id=…` surface exists.
 *
 * Ranking is DETERMINISTIC lexical coverage by default, so retrieval works with
 * NO embedding service. When an embedding provider is enabled and both the
 * query and the chunk carry a vector, cosine similarity is used instead; a
 * provider failure silently falls back to lexical. `top_k`, `min_relevance` and
 * `max_prompt_chars` are enforced, and every returned hit carries a VALIDATED
 * citation — a chunk whose citation cannot be validated is dropped rather than
 * cited with a guessed page.
 */
final class KnowledgeRetriever
{
    public function __construct(
        private readonly KnowledgeEmbeddingResolver $embeddings,
        private readonly KnowledgeCitationBuilder $citations,
    ) {}

    /**
     * @return list<KnowledgeHit>
     */
    public function retrieve(string $query, KnowledgeRetrievalContext $context): array
    {
        $query = trim($query);

        if ($query === '' || $context->tenantId === '') {
            return [];
        }

        $candidates = $this->candidates($context);

        if ($candidates === []) {
            return [];
        }

        $queryTerms = $this->terms($query);
        $queryVector = $this->queryVector($query);

        $scored = [];

        foreach ($candidates as $chunk) {
            $score = $this->score($chunk, $queryTerms, $queryVector);

            if ($score < $context->minRelevance) {
                continue;
            }

            $citation = $this->citations->build($chunk);

            if ($citation === null) {
                continue;
            }

            $scored[] = ['chunk' => $chunk, 'score' => $score, 'citation' => $citation];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            $byDocument = strcmp((string) $a['chunk']->document_id, (string) $b['chunk']->document_id);

            if ($byDocument !== 0) {
                return $byDocument;
            }

            if ((int) $a['chunk']->page_number !== (int) $b['chunk']->page_number) {
                return (int) $a['chunk']->page_number <=> (int) $b['chunk']->page_number;
            }

            return (int) $a['chunk']->chunk_index <=> (int) $b['chunk']->chunk_index;
        });

        $hits = [];
        $budget = $context->maxPromptChars;
        $used = 0;

        foreach ($scored as $row) {
            if (count($hits) >= $context->topK) {
                break;
            }

            $cost = mb_strlen((string) $row['chunk']->content);

            if ($used + $cost > $budget && $hits !== []) {
                break;
            }

            $used += $cost;

            /** @var KnowledgeDocument $document */
            $document = $row['chunk']->document;

            $hits[] = new KnowledgeHit($row['chunk'], $document, round((float) $row['score'], 6), $row['citation']);
        }

        return $hits;
    }

    /**
     * @return list<KnowledgeChunk>
     */
    private function candidates(KnowledgeRetrievalContext $context): array
    {
        return KnowledgeChunk::query()
            ->with('document')
            ->join('knowledge_documents as d', 'd.id', '=', 'knowledge_chunks.document_id')
            ->join('knowledge_document_versions as v', 'v.id', '=', 'knowledge_chunks.document_version_id')
            ->where('d.status', KnowledgeDocument::STATUS_PUBLISHED)
            ->whereColumn('v.version', 'd.current_version')
            ->where(function ($query) use ($context): void {
                $query->where(function ($inner) use ($context): void {
                    $inner->where('knowledge_chunks.scope', KnowledgeScope::TENANT)
                        ->where('knowledge_chunks.scope_key', $context->tenantId);
                });

                if ($context->includePlatform) {
                    $query->orWhere('knowledge_chunks.scope', KnowledgeScope::PLATFORM);
                }
            })
            ->when($context->language !== null && $context->language !== '', fn ($query) => $query->where('d.language', $context->language))
            ->select('knowledge_chunks.*')
            ->get()
            ->all();
    }

    /**
     * @param  list<string>  $queryTerms
     * @param  list<float>|null  $queryVector
     */
    private function score(KnowledgeChunk $chunk, array $queryTerms, ?array $queryVector): float
    {
        if ($queryVector !== null && $chunk->hasEmbedding()) {
            $cosine = $this->cosine($queryVector, array_map('floatval', (array) $chunk->embedding));

            if ($cosine !== null) {
                return max(0.0, min(1.0, $cosine));
            }
        }

        return $this->lexical($chunk, $queryTerms);
    }

    /**
     * @param  list<string>  $queryTerms
     */
    private function lexical(KnowledgeChunk $chunk, array $queryTerms): float
    {
        if ($queryTerms === []) {
            return 0.0;
        }

        $contentTerms = array_flip($this->terms((string) $chunk->content));
        $matched = 0;

        foreach ($queryTerms as $term) {
            if (isset($contentTerms[$term])) {
                $matched++;
            }
        }

        return $matched / count($queryTerms);
    }

    /**
     * Unicode-aware tokeniser — handles Indonesian, Malay and English alike.
     *
     * @return list<string>
     */
    private function terms(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        $terms = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) >= 2) {
                $terms[$part] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * @return list<float>|null
     */
    private function queryVector(string $query): ?array
    {
        $provider = $this->embeddings->resolve();

        if ($provider === null) {
            return null;
        }

        try {
            $vectors = $provider->embed([$query]);

            return isset($vectors[0]) && is_array($vectors[0]) ? $vectors[0] : null;
        } catch (Throwable) {
            // Lexical fallback.
            return null;
        }
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): ?float
    {
        $length = min(count($a), count($b));

        if ($length === 0) {
            return null;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return null;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
