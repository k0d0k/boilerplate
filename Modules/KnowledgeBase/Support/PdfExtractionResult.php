<?php

namespace Modules\KnowledgeBase\Support;

/**
 * WS9.A — the result of extracting text from a PDF.
 *
 * `pages` is a 0-based list whose index N holds the text of ORIGINAL PDF page
 * N+1. `charCount` counts the non-whitespace characters actually extracted; a
 * value of 0 means the file carried no selectable text (a scanned/image-only
 * PDF) and the caller must fail clearly rather than pretend it parsed.
 */
final class PdfExtractionResult
{
    /**
     * @param  list<string>  $pages
     */
    public function __construct(
        public readonly array $pages,
        public readonly int $charCount,
    ) {}

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function hasSelectableText(): bool
    {
        return $this->charCount > 0;
    }
}
