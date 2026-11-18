<?php

namespace Modules\KnowledgeBase\Support;

/**
 * WS9.A — a small, dependency-free extractor for SELECTABLE-TEXT PDFs.
 *
 * The platform ships no PDF-parsing library and the security invariant is to
 * begin with selectable-text PDFs and FAIL CLEARLY on scanned/image-only files
 * rather than pretend to have parsed them. This extractor therefore:
 *
 *  - walks the PDF's indirect objects and its page tree so the returned page
 *    order matches the REAL document pages (no invented page numbers);
 *  - decodes FlateDecode content streams (bounded, so a decompression bomb
 *    cannot exhaust memory) and pulls the text-showing operators out of them;
 *  - records each page's text separately, and reports a zero-character result
 *    when a page (or the whole file) has no selectable text;
 *  - treats the document strictly as data — nothing it contains is ever
 *    executed or interpreted as an instruction.
 *
 * It is deliberately conservative: anything it cannot parse yields empty text
 * rather than guessed text, so a downstream citation can never point at a page
 * whose content was fabricated.
 */
final class PdfTextExtractor
{
    /** Hard ceiling on a single decoded content stream (decompression bomb guard). */
    private const MAX_DECOMPRESSED_BYTES = 20_000_000;

    public function extract(string $bytes): PdfExtractionResult
    {
        if (! str_contains($bytes, '%PDF-')) {
            return new PdfExtractionResult([], 0);
        }

        $objects = $this->parseObjects($bytes);

        if ($objects === []) {
            return new PdfExtractionResult([], 0);
        }

        $pageObjects = [];
        foreach ($objects as $number => $object) {
            if (preg_match('#/Type\s*/Page(?![a-zA-Z])#', $object['dict'])) {
                $pageObjects[$number] = $object;
            }
        }

        if ($pageObjects === []) {
            return new PdfExtractionResult([], 0);
        }

        $ordered = $this->orderedPages($objects, $pageObjects);

        $pages = [];
        $charCount = 0;

        foreach ($ordered as $number) {
            $text = $this->normalize($this->extractPageText($objects, $pageObjects[$number]));
            $pages[] = $text;
            $charCount += mb_strlen(trim($text));
        }

        return new PdfExtractionResult($pages, $charCount);
    }

    /**
     * Parse every indirect object into a `['dict' => string, 'stream' => ?string]`
     * map keyed by object number. The scan is sequential so a `stream` is read up
     * to its `endstream` and never confused with text inside compressed bytes.
     *
     * @return array<int, array{dict: string, stream: ?string}>
     */
    private function parseObjects(string $bytes): array
    {
        $objects = [];
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            if (preg_match('/(\d+)\s+(\d+)\s+obj/', $bytes, $m, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                break;
            }

            $number = (int) $m[1][0];
            $bodyStart = $m[0][1] + strlen($m[0][0]);

            $endobj = strpos($bytes, 'endobj', $bodyStart);
            $streamPos = strpos($bytes, 'stream', $bodyStart);

            if ($streamPos !== false && ($endobj === false || $streamPos < $endobj)) {
                $dict = substr($bytes, $bodyStart, $streamPos - $bodyStart);
                $dataStart = $streamPos + strlen('stream');

                if (substr($bytes, $dataStart, 2) === "\r\n") {
                    $dataStart += 2;
                } elseif (($bytes[$dataStart] ?? '') === "\n" || ($bytes[$dataStart] ?? '') === "\r") {
                    $dataStart += 1;
                }

                $streamEnd = strpos($bytes, 'endstream', $dataStart);

                if ($streamEnd === false) {
                    $streamEnd = $length;
                }

                $objects[$number] = [
                    'dict' => $dict,
                    'stream' => substr($bytes, $dataStart, $streamEnd - $dataStart),
                ];

                $offset = $streamEnd + strlen('endstream');

                continue;
            }

            if ($endobj === false) {
                $endobj = $length;
            }

            $objects[$number] = [
                'dict' => substr($bytes, $bodyStart, $endobj - $bodyStart),
                'stream' => null,
            ];

            $offset = $endobj + strlen('endobj');
        }

        return $objects;
    }

    /**
     * Resolve the page order from the document's page tree, falling back to
     * ascending object-number order (valid when pages were written in order).
     *
     * @param  array<int, array{dict: string, stream: ?string}>  $objects
     * @param  array<int, array{dict: string, stream: ?string}>  $pageObjects
     * @return list<int>
     */
    private function orderedPages(array $objects, array $pageObjects): array
    {
        $root = null;

        foreach ($objects as $object) {
            if (preg_match('#/Type\s*/Catalog#', $object['dict']) === 1
                && preg_match('#/Pages\s+(\d+)\s+\d+\s+R#', $object['dict'], $m) === 1) {
                $root = (int) $m[1];

                break;
            }
        }

        if ($root !== null) {
            $order = [];
            $this->collectPages($objects, $root, $order, []);

            $filtered = array_values(array_filter(
                $order,
                static fn (int $number): bool => isset($pageObjects[$number]),
            ));

            if ($filtered !== []) {
                return $filtered;
            }
        }

        $numbers = array_keys($pageObjects);
        sort($numbers);

        return $numbers;
    }

    /**
     * @param  array<int, array{dict: string, stream: ?string}>  $objects
     * @param  list<int>  $order
     * @param  list<int>  $seen
     */
    private function collectPages(array $objects, int $node, array &$order, array $seen): void
    {
        if (in_array($node, $seen, true) || ! isset($objects[$node])) {
            return;
        }

        $seen[] = $node;
        $dict = $objects[$node]['dict'];

        if (preg_match('#/Type\s*/Page(?![a-zA-Z])#', $dict) === 1) {
            $order[] = $node;

            return;
        }

        if (preg_match('#/Kids\s*\[(.*?)\]#s', $dict, $m) === 1
            && preg_match_all('#(\d+)\s+\d+\s+R#', $m[1], $refs) > 0) {
            foreach ($refs[1] as $ref) {
                $this->collectPages($objects, (int) $ref, $order, $seen);
            }
        }
    }

    /**
     * @param  array<int, array{dict: string, stream: ?string}>  $objects
     * @param  array{dict: string, stream: ?string}  $page
     */
    private function extractPageText(array $objects, array $page): string
    {
        $refs = [];

        if (preg_match('#/Contents\s*\[(.*?)\]#s', $page['dict'], $m) === 1) {
            if (preg_match_all('#(\d+)\s+\d+\s+R#', $m[1], $r) > 0) {
                $refs = array_map('intval', $r[1]);
            }
        } elseif (preg_match('#/Contents\s+(\d+)\s+\d+\s+R#', $page['dict'], $m) === 1) {
            $refs = [(int) $m[1]];
        }

        $content = '';

        foreach ($refs as $ref) {
            if (isset($objects[$ref])) {
                $content .= "\n".$this->decodeStream($objects[$ref]);
            }
        }

        return $this->extractTextOperators($content);
    }

    /**
     * @param  array{dict: string, stream: ?string}  $object
     */
    private function decodeStream(array $object): string
    {
        $stream = $object['stream'];

        if ($stream === null) {
            return '';
        }

        if (preg_match('#/Filter#', $object['dict']) === 1
            && preg_match('#FlateDecode|/Fl(?![a-zA-Z])#', $object['dict']) === 1) {
            $decoded = @gzuncompress($stream, self::MAX_DECOMPRESSED_BYTES);

            if ($decoded === false) {
                $decoded = @gzinflate($stream, self::MAX_DECOMPRESSED_BYTES);
            }

            return $decoded === false ? '' : $decoded;
        }

        // An uncompressed stream is returned as-is; an unsupported filter yields
        // whatever raw bytes follow (the operator scan simply finds no text).
        return $stream;
    }

    /**
     * Pull the strings shown by Tj / TJ / ' / " operators, in document order.
     */
    private function extractTextOperators(string $content): string
    {
        if ($content === '') {
            return '';
        }

        $pattern = '#'
            .'\[(?P<arr>(?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ'
            .'|\((?P<lit>(?:\\\\.|[^\\\\()])*)\)'
            .'|<(?P<hex>[0-9A-Fa-f\s]*)>'
            .'#s';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === 0) {
            return '';
        }

        $out = '';

        foreach ($matches as $match) {
            if (($match['arr'] ?? '') !== '' || (($match[0][0] ?? '') === '[')) {
                $out .= $this->extractArray($match['arr']).' ';
            } elseif (($match['lit'] ?? '') !== '' || (($match[0][0] ?? '') === '(')) {
                $out .= $this->decodeLiteral($match['lit']).' ';
            } elseif (($match['hex'] ?? '') !== '' || (($match[0][0] ?? '') === '<')) {
                $out .= $this->maybeUtf16($this->decodeHex($match['hex'])).' ';
            }
        }

        return $out;
    }

    private function extractArray(string $inner): string
    {
        if (preg_match_all('#\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f\s]*>|-?\d+(?:\.\d+)?#s', $inner, $tokens) === 0) {
            return '';
        }

        $out = '';

        foreach ($tokens[0] as $token) {
            $first = $token[0] ?? '';

            if ($first === '(') {
                $out .= $this->decodeLiteral(substr($token, 1, -1));
            } elseif ($first === '<') {
                $out .= $this->maybeUtf16($this->decodeHex(substr($token, 1, -1)));
            } elseif ((float) $token <= -150.0) {
                // A large negative kern adjustment reads as a word space.
                $out .= ' ';
            }
        }

        return $out;
    }

    private function decodeLiteral(string $raw): string
    {
        $out = '';
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($char !== '\\') {
                $out .= $char;

                continue;
            }

            $i++;

            if ($i >= $length) {
                break;
            }

            $next = $raw[$i];

            $out .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'b' => "\x08",
                'f' => "\x0C",
                '(', ')', '\\' => $next,
                default => $this->decodeEscape($raw, $next, $i),
            };
        }

        return $this->maybeUtf16($out);
    }

    /**
     * Decode a backslash escape that may be an octal character code, advancing
     * `$i` past the consumed digits.
     */
    private function decodeEscape(string $raw, string $next, int &$i): string
    {
        if ($next < '0' || $next > '7') {
            return $next;
        }

        $octal = $next;

        while (strlen($octal) < 3 && $i + 1 < strlen($raw) && $raw[$i + 1] >= '0' && $raw[$i + 1] <= '7') {
            $i++;
            $octal .= $raw[$i];
        }

        return chr(octdec($octal) & 0xFF);
    }

    private function decodeHex(string $hex): string
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? '';

        if ($hex === '') {
            return '';
        }

        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        $binary = @hex2bin($hex);

        return $binary === false ? '' : $binary;
    }

    /**
     * Convert a UTF-16BE string (identified by its BOM) to UTF-8; otherwise
     * return the bytes unchanged (treated as single-byte WinAnsi text).
     */
    private function maybeUtf16(string $value): string
    {
        if (str_starts_with($value, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE');

            return $converted === false ? '' : $converted;
        }

        return $value;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
