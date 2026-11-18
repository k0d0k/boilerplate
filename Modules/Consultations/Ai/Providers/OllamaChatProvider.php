<?php

namespace Modules\Consultations\Ai\Providers;

use Illuminate\Support\Facades\Http;
use Modules\Consultations\Ai\Contracts\SinaChatProvider;
use Modules\Consultations\Ai\Dto\SinaChatMessage;
use Modules\Consultations\Ai\Dto\SinaCompletion;
use Modules\Consultations\Ai\Exceptions\SinaException;
use Modules\Consultations\Models\AiProviderConfig;

/**
 * Workstream 2 — the Ollama SINA chat adapter.
 *
 * Talks to a local/remote Ollama server over its native `/api/chat` endpoint
 * with `stream:false`. Token counters map from `prompt_eval_count`/`eval_count`.
 * An optional bearer token supports a reverse-proxied Ollama.
 */
final class OllamaChatProvider implements SinaChatProvider
{
    public function __construct(
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 30,
    ) {}

    public function providerId(): string
    {
        return AiProviderConfig::PROVIDER_OLLAMA;
    }

    public function chat(array $messages, array $options = []): SinaCompletion
    {
        $model = (string) ($options['model'] ?? $this->model);

        $payload = [
            'model' => $model,
            'messages' => array_map(static fn (SinaChatMessage $m): array => $m->toArray(), $messages),
            'stream' => false,
            'options' => array_filter([
                'temperature' => $options['temperature'] ?? null,
                'num_predict' => $options['max_tokens'] ?? null,
            ], static fn ($value): bool => $value !== null),
        ];

        $request = Http::timeout($this->timeout)->acceptJson();

        if (filled($this->apiKey)) {
            $request = $request->withToken((string) $this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/').'/api/chat', $payload);

        if ($response->failed()) {
            throw SinaException::unavailable('http '.$response->status());
        }

        $content = (string) $response->json('message.content', '');

        if ($content === '') {
            throw SinaException::invalidResponse('empty completion');
        }

        return new SinaCompletion(
            content: $content,
            provider: $this->providerId(),
            model: (string) $response->json('model', $model),
            promptTokens: (int) $response->json('prompt_eval_count', 0),
            completionTokens: (int) $response->json('eval_count', 0),
            raw: (array) $response->json(),
        );
    }
}
