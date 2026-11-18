<?php

namespace Modules\KnowledgeBase\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeDocumentEvent;
use Modules\KnowledgeBase\Models\KnowledgeDocumentVersion;
use Modules\KnowledgeBase\Models\KnowledgeExtractedPage;
use Modules\KnowledgeBase\Support\PdfTextExtractor;

/**
 * WS9.A — secure, idempotent PDF intake for the SINA knowledge base.
 *
 * One ingest:
 *  1. verifies the bytes are a real PDF and within the size limit;
 *  2. computes the SHA-256 and, when an identical file already exists in the
 *     SAME scope, returns that document unchanged (checksum de-duplication —
 *     never a second copy);
 *  3. extracts selectable text per ORIGINAL page, rejecting a scanned/unreadable
 *     PDF instead of pretending it parsed;
 *  4. writes the binary to a PRIVATE disk (opaque path, no public URL), stores
 *     the immutable version and the per-page text, and moves the document
 *     `uploaded -> processing -> review` with an audit trail.
 *
 * Nothing here is ever retrievable: only an authorised human publishing the
 * document (see {@see KnowledgeDocumentManager::publish()}) makes it eligible
 * for retrieval.
 */
final class KnowledgeIngestionService
{
    public function __construct(
        private readonly PdfTextExtractor $extractor,
        private readonly KnowledgeDocumentManager $manager,
    ) {}

    /**
     * @param  array{title: string, author?: ?string, publication?: ?string, published_on?: ?string, license?: ?string, source_url?: ?string, language?: ?string, original_filename?: ?string}  $attributes
     *
     * @throws KnowledgeBaseException
     */
    public function ingest(
        KnowledgeCollection $collection,
        string $sourcePath,
        array $attributes,
        ?User $actor = null,
    ): KnowledgeDocument {
        $bytes = @file_get_contents($sourcePath);

        if ($bytes === false || $bytes === '') {
            throw KnowledgeBaseException::unsupportedFileType();
        }

        if (! $this->looksLikePdf($bytes)) {
            throw KnowledgeBaseException::unsupportedFileType();
        }

        $maxBytes = $this->maxKilobytes() * 1024;

        if (strlen($bytes) > $maxBytes) {
            throw KnowledgeBaseException::fileTooLarge();
        }

        $sha256 = hash('sha256', $bytes);
        $scopeKey = $collection->scope_key ?? '';
        $language = (string) ($attributes['language'] ?? $collection->language);

        // Checksum de-duplication by scope: an identical file resolves to the
        // existing document rather than a duplicate.
        $existing = KnowledgeDocumentVersion::query()
            ->where('scope', $collection->scope)
            ->where('scope_key', $scopeKey)
            ->where('sha256', $sha256)
            ->first();

        if ($existing !== null) {
            return $existing->document()->firstOrFail();
        }

        $extraction = $this->extractor->extract($bytes);
        $pageCount = $extraction->pageCount();

        if ($pageCount > $this->maxPages()) {
            throw KnowledgeBaseException::tooManyPages();
        }

        $notExtractable = false;

        $document = DB::connection($collection->getConnectionName())->transaction(
            function () use ($collection, $bytes, $sha256, $attributes, $language, $extraction, $actor, $scopeKey, &$notExtractable): KnowledgeDocument {
                $document = KnowledgeDocument::query()->create([
                    'collection_id' => $collection->getKey(),
                    'scope' => $collection->scope,
                    'scope_key' => $scopeKey,
                    'tenant_id' => $collection->tenant_id,
                    'title' => (string) $attributes['title'],
                    'author' => $attributes['author'] ?? null,
                    'publication' => $attributes['publication'] ?? null,
                    'published_on' => $attributes['published_on'] ?? null,
                    'license' => $attributes['license'] ?? null,
                    'source_url' => $attributes['source_url'] ?? null,
                    'language' => $language,
                    'current_version' => 1,
                    'status' => KnowledgeDocument::STATUS_UPLOADED,
                    'uploaded_by' => $actor?->getKey(),
                ]);

                $this->manager->recordEvent(
                    $document,
                    KnowledgeDocumentEvent::ACTION_UPLOADED,
                    null,
                    KnowledgeDocument::STATUS_UPLOADED,
                    $actor,
                );

                $path = $this->storagePath($collection, (string) $document->getKey(), 1);
                Storage::disk($this->disk())->put($path, $bytes);

                $version = KnowledgeDocumentVersion::query()->create([
                    'document_id' => $document->getKey(),
                    'version' => 1,
                    'scope' => $collection->scope,
                    'scope_key' => $scopeKey,
                    'sha256' => $sha256,
                    'byte_size' => strlen($bytes),
                    'page_count' => $extraction->pageCount(),
                    'language' => $language,
                    'mime' => 'application/pdf',
                    'original_filename' => $attributes['original_filename'] ?? null,
                    'storage_disk' => $this->disk(),
                    'storage_path' => $path,
                    'extraction_status' => KnowledgeDocumentVersion::EXTRACTION_PENDING,
                    'created_by' => $actor?->getKey(),
                ]);

                $this->manager->beginProcessing($document, $actor);

                if (! $extraction->hasSelectableText()) {
                    $error = 'No selectable text was found. Scanned or image-only PDFs are not supported.';
                    $version->forceFill([
                        'extraction_status' => KnowledgeDocumentVersion::EXTRACTION_FAILED,
                        'extraction_error' => $error,
                    ])->save();
                    $this->manager->markExtractionFailed($document, $error, $actor);

                    // Commit the failed version + revoked document (traceable
                    // rejection) BEFORE surfacing the error, so the transaction
                    // rollback never erases the audit trail.
                    $notExtractable = true;

                    return $document->refresh();
                }

                $this->persistPages($version, $extraction->pages);

                $version->forceFill(['extraction_status' => KnowledgeDocumentVersion::EXTRACTION_EXTRACTED])->save();

                $this->manager->recordEvent(
                    $document,
                    KnowledgeDocumentEvent::ACTION_EXTRACTED,
                    KnowledgeDocument::STATUS_PROCESSING,
                    KnowledgeDocument::STATUS_PROCESSING,
                    $actor,
                    ['pages' => $extraction->pageCount()],
                );

                $this->manager->markReadyForReview($document, $actor);

                return $document->refresh();
            },
        );

        if ($notExtractable) {
            throw KnowledgeBaseException::notExtractable();
        }

        return $document;
    }

    /**
     * @param  list<string>  $pages
     */
    private function persistPages(KnowledgeDocumentVersion $version, array $pages): void
    {
        foreach ($pages as $index => $content) {
            KnowledgeExtractedPage::query()->create([
                'document_version_id' => $version->getKey(),
                'page_number' => $index + 1,
                'content' => $content,
                'char_count' => mb_strlen(trim($content)),
            ]);
        }
    }

    private function looksLikePdf(string $bytes): bool
    {
        return str_contains(substr($bytes, 0, 1024), '%PDF-');
    }

    private function storagePath(KnowledgeCollection $collection, string $documentId, int $version): string
    {
        $prefix = trim((string) config('lba.knowledge.prefix', 'knowledge'), '/');
        $scope = $collection->scope_key !== '' && $collection->scope_key !== null
            ? $collection->scope_key
            : 'platform';

        return sprintf('%s/%s/%s/v%d.pdf', $prefix, $scope, $documentId, $version);
    }

    private function disk(): string
    {
        return (string) config('lba.knowledge.disk', 'lba-knowledge');
    }

    private function maxKilobytes(): int
    {
        return max(1, (int) config('lba.knowledge.max_kilobytes', 51200));
    }

    private function maxPages(): int
    {
        return max(1, (int) config('lba.knowledge.max_pages', 1000));
    }
}
