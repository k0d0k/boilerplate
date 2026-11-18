<?php

namespace Modules\KnowledgeBase\Support;

use Modules\KnowledgeBase\Models\KnowledgeChunk;
use Modules\KnowledgeBase\Models\KnowledgeDocument;

/**
 * WS9.B — one retrieved, AUTHORIZED knowledge chunk.
 *
 * A hit only ever exists for a PUBLISHED document of the acting tenant (or a
 * globally published platform source). It carries the immutable provenance
 * (`page`) and a validated structured citation, so WS9.C can ground an answer
 * and attach a real page reference — neither the page nor the citation is ever
 * fabricated.
 */
final class KnowledgeHit
{
    /**
     * @param  array{document_id: string, version: int, page: int, title: string, published_on: ?string, url: string}  $citation
     */
    public function __construct(
        public readonly KnowledgeChunk $chunk,
        public readonly KnowledgeDocument $document,
        public readonly float $score,
        public readonly array $citation,
    ) {}

    public function page(): int
    {
        return (int) $this->chunk->page_number;
    }

    public function content(): string
    {
        return (string) $this->chunk->content;
    }

    public function title(): string
    {
        return (string) $this->document->title;
    }

    public function isPlatform(): bool
    {
        return $this->document->scope === KnowledgeScope::PLATFORM;
    }
}
