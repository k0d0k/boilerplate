<?php

namespace Modules\KnowledgeBase\Support;

/**
 * WS9.D — a tiny, deterministic, selectable-text PDF writer.
 *
 * Produces a minimal but REAL PDF whose pages carry extractable text, so the
 * evaluation harness and staging UAT can build a synthetic knowledge corpus
 * WITHOUT shipping any copyrighted or patient document. Each entry in
 * `$pageTexts` becomes exactly one page, in order, so the extractor must
 * recover the ACTUAL page numbers.
 *
 * This is not a general-purpose PDF library: it exists only to create small,
 * non-patient test fixtures and is never used on the intake path.
 */
final class SyntheticPdf
{
    /**
     * @param  list<string>  $pageTexts
     */
    public static function make(array $pageTexts): string
    {
        $objects = [];
        $refs = [];
        $number = 3;

        foreach ($pageTexts as $text) {
            $page = $number;
            $content = $number + 1;
            $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
            $objects[$page] = "<< /Type /Page /Parent 2 0 R /Contents {$content} 0 R >>";
            $objects[$content] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $refs[] = "{$page} 0 R";
            $number += 2;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $refs).'] /Count '.count($pageTexts).' >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n";

        foreach ($objects as $num => $body) {
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        return $pdf."trailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}
