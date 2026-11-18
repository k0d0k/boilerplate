<?php

namespace Modules\KnowledgeBase\Providers;

use Illuminate\Support\Facades\Http;
use Modules\KnowledgeBase\Contracts\KnowledgeEmbeddingProvider;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;

/**
 * WS9.B — the OpenAI-compatible embedding adapter.
 *
 * Talks to any `/embeddings` endpoint (OpenAI, vLLM, a local gateway) using the
 * same private base URL pattern as the SINA OpenAI-compatible chat adapter. It
 * is pinned to an explicit model + dimension and surfaces a failure as a
 * KnowledgeBaseException so retrieval degrades to lexical search.
 */
final class OpenAiCompatibleEmbeddingProvider implements KnowledgeEmbeddingProvider
{
    public function __construct(
        private readonly string $model,
        private readonly int $dimensions,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 30,
    ) {}

    public function providerId(): string
    {
        return 'openai_compatible';
    }

    public function modelId(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $payload = [
            'model' => $this->model,
            'input' => array_values($texts),
        ];

        if ($this->dimensions > 0) {
            $payload['dimensions'] = $this->dimensions;
        }

        $request = Http::timeout($this->timeout)->acceptJson();

        if (filled($this->apiKey)) {
            $request = $request->withToken((string) $this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/').'/embeddings', $payload);

        if ($response->failed()) {
            throw KnowledgeBaseException::embeddingFailed($this->providerId(), 'http '.$response->status());
        }

        $rows = $response->json('data');

        if (! is_array($rows) || count($rows) !== count($texts)) {
            throw KnowledgeBaseException::embeddingFailed($this->providerId(), 'unexpected payload');
        }

        $vectors = [];

        foreach ($rows as $row) {
            $vector = is_array($row) ? ($row['embedding'] ?? null) : null;

            if (! is_array($vector) || $vector === []) {
                throw KnowledgeBaseException::embeddingFailed($this->providerId(), 'empty vector');
            }

            $vectors[] = array_map(static fn ($value): float => (float) $value, array_values($vector));
        }

        return $vectors;
    }
}
