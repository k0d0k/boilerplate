<?php

namespace Modules\KnowledgeBase\Services;

use Modules\KnowledgeBase\Contracts\KnowledgeEmbeddingProvider;
use Modules\KnowledgeBase\Models\KnowledgeChunk;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeDocumentVersion;
use Throwable;

/**
 * WS9.B — the page-aware retrieval indexer.
 *
 * Reindexing a document is IDEMPOTENT: the chunk set is derived deterministically
 * from the current version's extracted pages, existing rows are updated in place
 * by (version, chunk_index) and any surplus rows are removed. Chunks belonging
 * to a SUPERSEDED version are invalidated (deleted), so only the current
 * version is ever retrievable — a version bump can never leave stale citations.
 *
 * Embeddings are attached opportunistically. When no embedding provider is
 * configured, or when a provider call fails, the chunk is still indexed and the
 * deterministic lexical index serves retrieval — an embedding outage never
 * blocks an answer. The pinned embedding version is recorded on every embedded
 * chunk so a rebuild is reproducible.
 */
final class KnowledgeIndexer
{
    public function __construct(
        private readonly KnowledgeChunker $chunker,
        private readonly KnowledgeEmbeddingResolver $embeddings,
    ) {}

    /**
     * (Re)build the index for the document's CURRENT version.
     *
     * @return int the number of indexed chunks
     */
    public function reindex(KnowledgeDocument $document): int
    {
        $version = $document->currentVersionRecord();

        if (! $version instanceof KnowledgeDocumentVersion || ! $version->isExtracted()) {
            return 0;
        }

        $chunks = $this->buildChunks($version);

        // Invalidate chunks of any superseded version of this document.
        KnowledgeChunk::query()
            ->where('document_id', $document->getKey())
            ->where('document_version_id', '!=', $version->getKey())
            ->delete();

        $provider = $this->embeddings->resolve();
        $embeddingVersion = $this->embeddings->version();
        $vectors = $this->embed($provider, $chunks);

        foreach ($chunks as $index => $chunk) {
            $vector = $vectors[$index] ?? null;

            KnowledgeChunk::query()->updateOrCreate(
                [
                    'document_version_id' => $version->getKey(),
                    'chunk_index' => $index,
                ],
                [
                    'document_id' => $document->getKey(),
                    'collection_id' => $document->collection_id,
                    'scope' => $document->scope,
                    'scope_key' => $document->scope_key,
                    'page_number' => $chunk['page_number'],
                    'content' => $chunk['content'],
                    'char_count' => $chunk['char_count'],
                    'token_estimate' => $chunk['token_estimate'],
                    'content_hash' => $chunk['content_hash'],
                    'embedding_status' => $vector === null
                        ? ($provider === null ? KnowledgeChunk::EMBEDDING_PENDING : KnowledgeChunk::EMBEDDING_FAILED)
                        : KnowledgeChunk::EMBEDDING_READY,
                    'embedding_version' => $vector === null ? null : $embeddingVersion,
                    'embedding' => $vector,
                ],
            );
        }

        // Remove surplus chunks from a previously longer version.
        KnowledgeChunk::query()
            ->where('document_version_id', $version->getKey())
            ->where('chunk_index', '>=', count($chunks))
            ->delete();

        return count($chunks);
    }

    /**
     * Purge every chunk of a document (revocation / purge semantics).
     */
    public function purge(KnowledgeDocument $document): int
    {
        return KnowledgeChunk::query()->where('document_id', $document->getKey())->delete();
    }

    /**
     * @return list<array{page_number: int, content: string, char_count: int, token_estimate: int, content_hash: string}>
     */
    private function buildChunks(KnowledgeDocumentVersion $version): array
    {
        $chunks = [];

        foreach ($version->pages()->get() as $page) {
            $content = (string) $page->content;

            if (trim($content) === '') {
                continue;
            }

            foreach ($this->chunker->chunkPage($content, (int) $page->page_number) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * Embed the chunk contents in one batch, returning an index-aligned list of
     * vectors (null where embedding failed). A provider failure degrades to
     * lexical-only rather than aborting the reindex.
     *
     * @param  list<array{content: string}>  $chunks
     * @return list<list<float>>|null
     */
    private function embed(?KnowledgeEmbeddingProvider $provider, array $chunks): ?array
    {
        if ($provider === null || $chunks === []) {
            return null;
        }

        try {
            return $provider->embed(array_map(static fn (array $chunk): string => $chunk['content'], $chunks));
        } catch (Throwable) {
            // The lexical index remains fully functional; embeddings are a
            // best-effort enhancement, never a hard dependency.
            return null;
        }
    }
}
