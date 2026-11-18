<?php

namespace Modules\KnowledgeBase\Services;

/**
 * WS9.B — deterministic, page-aware chunking.
 *
 * Text is ALWAYS chunked one ORIGINAL page at a time, so a chunk can never span
 * two pages and every downstream citation keeps the true source page. The split
 * is sentence-aware with a bounded character overlap, so reindexing the same
 * input yields byte-identical chunks (and identical content hashes).
 */
final class KnowledgeChunker
{
    /**
     * Split one page's text into retrieval chunks.
     *
     * @return list<array{page_number: int, content: string, char_count: int, token_estimate: int, content_hash: string}>
     */
    public function chunkPage(string $text, int $pageNumber): array
    {
        $normalised = $this->normalise($text);

        if ($normalised === '') {
            return [];
        }

        $target = max(1, (int) config('lba.knowledge.chunking.target_chars', 1200));
        $overlap = max(0, (int) config('lba.knowledge.chunking.overlap_chars', 200));
        $min = max(0, (int) config('lba.knowledge.chunking.min_chars', 40));

        // The overlap must stay well below the target or the window would never
        // advance; clamp it to at most half the target.
        $overlap = min($overlap, intdiv($target, 2));

        $chunks = [];
        $current = '';

        foreach ($this->segments($normalised) as $segment) {
            foreach ($this->windows($segment, $target, $overlap) as $window) {
                if ($current === '') {
                    $current = $window;

                    continue;
                }

                if (mb_strlen($current) + 1 + mb_strlen($window) <= $target) {
                    $current .= ' '.$window;

                    continue;
                }

                $chunks[] = $current;
                // Seed the next chunk with the trailing overlap of the previous.
                $current = $this->tail($current, $overlap);
                $current = $current === '' ? $window : $current.' '.$window;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $result = [];

        foreach ($chunks as $content) {
            $content = trim($content);

            if ($content === '') {
                continue;
            }

            $result[] = [
                'page_number' => $pageNumber,
                'content' => $content,
                'char_count' => mb_strlen($content),
                'token_estimate' => (int) ceil(mb_strlen($content) / 4),
                'content_hash' => hash('sha256', $content),
            ];
        }

        // Drop tiny trailing fragments ONLY when a longer chunk exists, so a
        // short page still yields its content rather than being silently lost.
        if (count($result) > 1) {
            $filtered = array_values(array_filter(
                $result,
                static fn (array $chunk): bool => $chunk['char_count'] >= $min,
            ));

            if ($filtered !== []) {
                $result = $filtered;
            }
        }

        return $result;
    }

    /**
     * Collapse all whitespace runs to single spaces; keep the text otherwise
     * byte-faithful (never invent or reorder content).
     */
    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[ ]+/u', ' ', $text);
        $text = (string) preg_replace('/\s*\n\s*/u', ' ', $text);

        return trim($text);
    }

    /**
     * Split into sentence-like segments; a segment is a boundary we prefer not
     * to cut across.
     *
     * @return list<string>
     */
    private function segments(string $text): array
    {
        $parts = preg_split('/(?<=[.!?。！？])\s+/u', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
    }

    /**
     * Hard-split any segment longer than the target into fixed windows with a
     * bounded overlap, so a single huge "sentence" can never overflow the index.
     *
     * @return list<string>
     */
    private function windows(string $segment, int $target, int $overlap): array
    {
        if (mb_strlen($segment) <= $target) {
            return [$segment];
        }

        $windows = [];
        $length = mb_strlen($segment);
        $step = max(1, $target - $overlap);
        $offset = 0;

        while ($offset < $length) {
            $windows[] = trim(mb_substr($segment, $offset, $target));
            $offset += $step;
        }

        return array_values(array_filter($windows, static fn (string $window): bool => $window !== ''));
    }

    private function tail(string $text, int $overlap): string
    {
        if ($overlap <= 0) {
            return '';
        }

        return trim(mb_substr($text, -$overlap));
    }
}
