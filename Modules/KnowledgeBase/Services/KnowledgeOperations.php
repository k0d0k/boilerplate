<?php

namespace Modules\KnowledgeBase\Services;

use Modules\KnowledgeBase\Models\KnowledgeChunk;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeDocumentEvent;
use Modules\KnowledgeBase\Models\KnowledgeDocumentVersion;
use Modules\KnowledgeBase\Support\KnowledgeRetrievalContext;

/**
 * WS9.D — the operator-facing knowledge operations surface.
 *
 * This is a READ-ONLY diagnostic and re-index helper for the Principal/Clinic
 * knowledge-management UI. It deliberately exposes NO clinical data:
 *
 *  - `status()` reports the indexing state of a document's CURRENT version
 *    (pages, chunks, embedded/failed/pending counts, pinned embedding version);
 *  - `reindex()` re-runs the idempotent indexer and returns the chunk count so a
 *    failed indexing job can be retried from the UI;
 *  - `versionComparison()` lists the immutable versions of a document so an
 *    operator can compare page/chunk counts across revisions;
 *  - `probe()` runs a retrieval for an OPERATOR-TYPED query and returns only
 *    document provenance (title, page, score, citation URL). It never returns a
 *    patient message, a patient identifier or another tenant's document — the
 *    tenant is always the server-derived value passed by the caller.
 */
final class KnowledgeOperations
{
    public function __construct(
        private readonly KnowledgeIndexer $indexer,
        private readonly KnowledgeRetriever $retriever,
    ) {}

    /**
     * Indexing diagnostics for the document's current version.
     *
     * @return array{version: ?int, extracted: bool, page_count: int, chunks: int, embedded: int, failed: int, pending: int, embedding_version: ?string}
     */
    public function status(KnowledgeDocument $document): array
    {
        $version = $document->currentVersionRecord();

        if (! $version instanceof KnowledgeDocumentVersion) {
            return [
                'version' => null,
                'extracted' => false,
                'page_count' => 0,
                'chunks' => 0,
                'embedded' => 0,
                'failed' => 0,
                'pending' => 0,
                'embedding_version' => null,
            ];
        }

        $chunks = KnowledgeChunk::query()
            ->where('document_version_id', $version->getKey())
            ->get();

        return [
            'version' => (int) $version->version,
            'extracted' => $version->isExtracted(),
            'page_count' => (int) $version->page_count,
            'chunks' => $chunks->count(),
            'embedded' => $chunks->where('embedding_status', KnowledgeChunk::EMBEDDING_READY)->count(),
            'failed' => $chunks->where('embedding_status', KnowledgeChunk::EMBEDDING_FAILED)->count(),
            'pending' => $chunks->where('embedding_status', KnowledgeChunk::EMBEDDING_PENDING)->count(),
            'embedding_version' => $chunks->firstWhere('embedding_version', '!=', null)?->embedding_version,
        ];
    }

    /**
     * Retry the idempotent index build for the current version. Returns the
     * number of indexed chunks.
     */
    public function reindex(KnowledgeDocument $document): int
    {
        return $this->indexer->reindex($document);
    }

    /**
     * Immutable versions of a document, oldest first, with their chunk counts.
     *
     * @return list<array{version: int, page_count: int, chunks: int, sha256: string, extracted: bool, created_at: ?string, current: bool}>
     */
    public function versionComparison(KnowledgeDocument $document): array
    {
        $rows = [];

        foreach ($document->versions()->get() as $version) {
            $chunks = KnowledgeChunk::query()
                ->where('document_version_id', $version->getKey())
                ->count();

            $rows[] = [
                'version' => (int) $version->version,
                'page_count' => (int) $version->page_count,
                'chunks' => $chunks,
                'sha256' => (string) $version->sha256,
                'extracted' => $version->isExtracted(),
                'created_at' => $version->created_at?->toDateTimeString(),
                'current' => (int) $version->version === (int) $document->current_version,
            ];
        }

        return $rows;
    }

    /**
     * The most recent lifecycle audit entries for a document.
     *
     * @return list<array{action: string, from: ?string, to: ?string, at: ?string}>
     */
    public function recentEvents(KnowledgeDocument $document, int $limit = 10): array
    {
        return $document->events()
            ->limit($limit)
            ->get()
            ->map(fn (KnowledgeDocumentEvent $event): array => [
                'action' => (string) $event->action,
                'from' => $event->from_status,
                'to' => $event->to_status,
                'at' => $event->occurred_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Read-only retrieval audit for an operator-typed query.
     *
     * Returns ONLY document provenance — never patient text. The tenant is the
     * server-derived value supplied by the caller, so this can never be pointed
     * at another tenant.
     *
     * @return list<array{document_id: string, version: int, page: int, title: string, score: float, url: string, platform: bool}>
     */
    public function probe(string $tenantId, string $query, ?string $language = null): array
    {
        return $this->hits($tenantId, $query, $language);
    }

    /**
     * @return list<array{document_id: string, version: int, page: int, title: string, score: float, url: string, platform: bool}>
     */
    private function hits(string $tenantId, string $query, ?string $language): array
    {
        $context = KnowledgeRetrievalContext::forTenant($tenantId, ['language' => $language]);
        $rows = [];

        foreach ($this->retriever->retrieve($query, $context) as $hit) {
            $rows[] = [
                'document_id' => (string) $hit->document->getKey(),
                'version' => (int) ($hit->citation['version'] ?? $hit->document->current_version),
                'page' => (int) ($hit->citation['page'] ?? $hit->page()),
                'title' => $hit->title(),
                'score' => round($hit->score, 4),
                'url' => (string) ($hit->citation['url'] ?? ''),
                'platform' => $hit->isPlatform(),
            ];
        }

        return $rows;
    }
}
