<?php

namespace Modules\KnowledgeBase\Contracts;

use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;

/**
 * WS9.B — the embedding provider contract.
 *
 * An embedding provider turns text into a dense vector for the retrieval index.
 * It is deliberately SEPARATE from the SINA chat provider: the chat generation
 * model (`sina-med-cloud`/MedGemma via Ollama) is NOT assumed to be a reliable
 * embedding model, so retrieval pins its OWN embedding model/version/dimensions
 * and degrades to the deterministic lexical index when no provider is enabled
 * or when a call fails.
 */
interface KnowledgeEmbeddingProvider
{
    /** The provider identifier (ollama | openai_compatible). */
    public function providerId(): string;

    /** The pinned embedding model id. */
    public function modelId(): string;

    /** The pinned vector dimension. */
    public function dimensions(): int;

    /**
     * Embed a batch of texts, preserving input order.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws KnowledgeBaseException
     */
    public function embed(array $texts): array;
}
