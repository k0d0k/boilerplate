<?php

namespace Modules\Consultations\Ai\Providers;

use Illuminate\Support\Facades\Http;
use Modules\Consultations\Ai\Contracts\SinaChatProvider;
use Modules\Consultations\Ai\Dto\SinaChatMessage;
use Modules\Consultations\Ai\Dto\SinaCompletion;
use Modules\Consultations\Ai\Exceptions\SinaException;
use Modules\Consultations\Models\AiProviderConfig;

/**
 * Workstream 2 — the OpenAI-compatible SINA chat adapter.
 *
 * Speaks the `/chat/completions` contract shared by OpenAI, Azure OpenAI,
 * OpenRouter, vLLM, LM Studio and other compatible gateways. Token counters map
 * from `usage.prompt_tokens`/`usage.completion_tokens`.
 */
final class OpenAiCompatibleChatProvider implements SinaChatProvider
{
    public function __construct(
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 30,
    ) {}

    public function providerId(): string
    {
        return AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE;
    }

    public function chat(array $messages, array $options = []): SinaCompletion
    {
        $model = (string) ($options['model'] ?? $this->model);

        $payload = array_filter([
            'model' => $model,
            'messages' => array_map(static fn (SinaChatMessage $m): array => $m->toArray(), $messages),
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
        ], static fn ($value): bool => $value !== null);

        $request = Http::timeout($this->timeout)->acceptJson();

        if (filled($this->apiKey)) {
            $request = $request->withToken((string) $this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);

        if ($response->failed()) {
            throw SinaException::unavailable('http '.$response->status());
        }

        $content = (string) $response->json('choices.0.message.content', '');

        if ($content === '') {
            throw SinaException::invalidResponse('empty completion');
        }

        return new SinaCompletion(
            content: $content,
            provider: $this->providerId(),
            model: (string) $response->json('model', $model),
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
            raw: (array) $response->json(),
        );
    }
}
