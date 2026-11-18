<?php

namespace Modules\KnowledgeBase\Providers;

use Illuminate\Support\Facades\Http;
use Modules\KnowledgeBase\Contracts\KnowledgeEmbeddingProvider;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;

/**
 * WS9.B — the Ollama embedding adapter.
 *
 * Talks to an Ollama server's embeddings endpoint over the SAME private base URL
 * the SINA chat adapter uses. It is used ONLY for the retrieval index and is
 * pinned to an explicit model + dimension. A failure is surfaced as a
 * KnowledgeBaseException so the retriever can fall back to lexical search; it
 * never blocks an answer.
 */
final class OllamaEmbeddingProvider implements KnowledgeEmbeddingProvider
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
        return 'ollama';
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

        $vectors = [];

        foreach ($texts as $text) {
            $request = Http::timeout($this->timeout)->acceptJson();

            if (filled($this->apiKey)) {
                $request = $request->withToken((string) $this->apiKey);
            }

            $response = $request->post(rtrim($this->baseUrl, '/').'/api/embeddings', [
                'model' => $this->model,
                'prompt' => $text,
            ]);

            if ($response->failed()) {
                throw KnowledgeBaseException::embeddingFailed($this->providerId(), 'http '.$response->status());
            }

            $vector = $response->json('embedding');

            if (! is_array($vector) || $vector === []) {
                throw KnowledgeBaseException::embeddingFailed($this->providerId(), 'empty vector');
            }

            $vectors[] = array_map(static fn ($value): float => (float) $value, array_values($vector));
        }

        return $vectors;
    }
}
