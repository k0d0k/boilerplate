<?php

namespace Modules\KnowledgeBase\Services;

use Modules\KnowledgeBase\Contracts\KnowledgeEmbeddingProvider;
use Modules\KnowledgeBase\Providers\OllamaEmbeddingProvider;
use Modules\KnowledgeBase\Providers\OpenAiCompatibleEmbeddingProvider;

/**
 * WS9.B — resolves the embedding provider for the retrieval index.
 *
 * DISABLED by default (`provider` = none): SINA then relies on the deterministic
 * lexical index and NO external embedding service is required. When a provider
 * is enabled it is instantiated from the pinned config (model + dimensions), so
 * the index version is reproducible. A misconfigured provider resolves to null
 * rather than throwing — retrieval simply falls back to lexical search.
 */
final class KnowledgeEmbeddingResolver
{
    public const PROVIDER_NONE = 'none';

    public const PROVIDER_OLLAMA = 'ollama';

    public const PROVIDER_OPENAI_COMPATIBLE = 'openai_compatible';

    /**
     * The configured provider, or null when embeddings are disabled/misconfigured.
     */
    public function resolve(): ?KnowledgeEmbeddingProvider
    {
        $provider = (string) config('lba.knowledge.embedding.provider', self::PROVIDER_NONE);

        $baseUrl = (string) config("lba.knowledge.embedding.defaults.{$provider}.base_url", '');
        $model = (string) config("lba.knowledge.embedding.defaults.{$provider}.model", '');
        $dimensions = (int) config("lba.knowledge.embedding.defaults.{$provider}.dimensions", 0);
        $apiKey = config("lba.knowledge.embedding.defaults.{$provider}.api_key");

        if ($provider === self::PROVIDER_NONE || $baseUrl === '' || $model === '') {
            return null;
        }

        $apiKey = is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
        $timeout = max(1, (int) config('lba.knowledge.embedding.timeout', 30));

        return match ($provider) {
            self::PROVIDER_OLLAMA => new OllamaEmbeddingProvider($model, $dimensions, $baseUrl, $apiKey, $timeout),
            self::PROVIDER_OPENAI_COMPATIBLE => new OpenAiCompatibleEmbeddingProvider($model, $dimensions, $baseUrl, $apiKey, $timeout),
            default => null,
        };
    }

    /**
     * A stable, pinned index version string ({provider}:{model}:{dimensions}) or
     * null when embeddings are disabled. Recorded on every chunk so a reindex is
     * exact and a stale index can be detected.
     */
    public function version(): ?string
    {
        $provider = $this->resolve();

        if ($provider === null) {
            return null;
        }

        return $provider->providerId().':'.$provider->modelId().':'.$provider->dimensions();
    }
}
