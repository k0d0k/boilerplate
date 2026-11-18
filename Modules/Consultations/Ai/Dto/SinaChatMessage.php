<?php

namespace Modules\Consultations\Ai\Dto;

/**
 * Workstream 2 — one chat turn handed to a SINA provider.
 *
 * A minimal, provider-neutral role/content pair so the Ollama and
 * OpenAI-compatible adapters can map it to their own wire format.
 */
final readonly class SinaChatMessage
{
    public function __construct(
        public string $role,
        public string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
