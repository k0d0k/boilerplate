<?php

namespace Modules\Consultations\Ai\Dto;

/**
 * Workstream 2 — a normalized SINA provider completion.
 *
 * Providers report token counters differently (Ollama `prompt_eval_count` /
 * `eval_count`; OpenAI `usage.prompt_tokens` / `usage.completion_tokens`), so
 * each adapter maps them onto these integer fields. `raw` retains the untouched
 * provider payload for diagnostics; it is never persisted centrally.
 */
final readonly class SinaCompletion
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        public array $raw = [],
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
