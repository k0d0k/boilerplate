<?php

namespace Modules\KnowledgeBase\Support;

/**
 * WS9.B — the trusted parameters of one retrieval request.
 *
 * The `tenantId` is ALWAYS a server-derived value (the verified tenant context
 * or a token's clinic) — never client input. `includePlatform` gates whether
 * globally published platform sources are eligible; a tenant-scope source is
 * only ever reachable by its owning tenant. `topK`, `minRelevance` and
 * `maxPromptChars` bound the answer surface so a query can neither flood the
 * model context nor pull in low-relevance noise.
 */
final class KnowledgeRetrievalContext
{
    public function __construct(
        public readonly string $tenantId,
        public readonly bool $includePlatform = true,
        public readonly ?string $language = null,
        public readonly int $topK = 6,
        public readonly float $minRelevance = 0.12,
        public readonly int $maxPromptChars = 6000,
    ) {}

    /**
     * Build a context from the platform defaults, with optional overrides.
     *
     * @param  array{include_platform?: bool, language?: ?string, top_k?: int, min_relevance?: float, max_prompt_chars?: int}  $overrides
     */
    public static function forTenant(string $tenantId, array $overrides = []): self
    {
        return new self(
            tenantId: $tenantId,
            includePlatform: (bool) ($overrides['include_platform'] ?? config('lba.knowledge.retrieval.include_platform', true)),
            language: $overrides['language'] ?? null,
            topK: max(1, (int) ($overrides['top_k'] ?? config('lba.knowledge.retrieval.top_k', 6))),
            minRelevance: (float) ($overrides['min_relevance'] ?? config('lba.knowledge.retrieval.min_relevance', 0.12)),
            maxPromptChars: max(1, (int) ($overrides['max_prompt_chars'] ?? config('lba.knowledge.retrieval.max_prompt_chars', 6000))),
        );
    }
}
