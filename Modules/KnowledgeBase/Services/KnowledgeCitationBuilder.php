<?php

namespace Modules\KnowledgeBase\Services;

use Modules\KnowledgeBase\Models\KnowledgeChunk;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeExtractedPage;

/**
 * WS9.B — the citation builder/validator.
 *
 * A citation is produced ONLY for a chunk whose document is currently PUBLISHED
 * and whose version is the document's CURRENT version and whose page exists in
 * that version. If any check fails the builder returns null, so a stale,
 * revoked or cross-version chunk can never yield a fabricated bibliography entry
 * or a wrong page number. The `url` is a PRIVATE authenticated route — the
 * controller re-authorizes the acting user's scope on every request.
 */
final class KnowledgeCitationBuilder
{
    /**
     * @return array{document_id: string, version: int, page: int, title: string, published_on: ?string, url: string}|null
     */
    public function build(KnowledgeChunk $chunk): ?array
    {
        $document = $chunk->document()->first();

        if (! $document instanceof KnowledgeDocument || ! $document->isPublished()) {
            return null;
        }

        // The chunk must belong to the CURRENT version of the document.
        if ($chunk->document_version_id === null || (string) $chunk->document_version_id === '') {
            return null;
        }

        $version = $document->currentVersionRecord();

        if ($version === null || (string) $version->getKey() !== (string) $chunk->document_version_id) {
            return null;
        }

        $pageNumber = (int) $chunk->page_number;

        $pageExists = KnowledgeExtractedPage::query()
            ->where('document_version_id', $version->getKey())
            ->where('page_number', $pageNumber)
            ->exists();

        if (! $pageExists) {
            return null;
        }

        return [
            'document_id' => (string) $document->getKey(),
            'version' => (int) $document->current_version,
            'page' => $pageNumber,
            'title' => (string) $document->title,
            'published_on' => $document->published_on?->toDateString(),
            'url' => route('lba.knowledge.documents.download', ['document' => $document->getKey()]),
        ];
    }
}
