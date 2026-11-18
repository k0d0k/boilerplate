<?php

namespace Modules\KnowledgeBase\Evaluation;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Models\KnowledgeChunk;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeDocumentEvent;
use Modules\KnowledgeBase\Models\KnowledgeDocumentVersion;
use Modules\KnowledgeBase\Models\KnowledgeExtractedPage;
use Modules\KnowledgeBase\Services\KnowledgeCollectionManager;
use Modules\KnowledgeBase\Services\KnowledgeDocumentManager;
use Modules\KnowledgeBase\Services\KnowledgeIndexer;
use Modules\KnowledgeBase\Services\KnowledgeIngestionService;
use Modules\KnowledgeBase\Support\KnowledgeScope;
use Modules\KnowledgeBase\Support\SyntheticPdf;

/**
 * WS9.D — seeds the synthetic evaluation corpus into ONE tenant-scope
 * collection so the {@see KnowledgeEvaluationHarness} can run against real
 * retrieval.
 *
 * The corpus is entirely synthetic and non-patient. The seeder is idempotent
 * (an identical PDF is de-duplicated by checksum) and it only ever writes to a
 * collection whose key is `eval-{locale}` in the CALLER-supplied tenant scope —
 * it can never touch another tenant's knowledge. `purge()` removes exactly that
 * collection's rows and files, so a staging run leaves no residue.
 */
final class KnowledgeEvaluationSeeder
{
    public function __construct(
        private readonly KnowledgeIngestionService $ingestion,
        private readonly KnowledgeDocumentManager $manager,
        private readonly KnowledgeIndexer $indexer,
    ) {}

    public static function collectionKey(string $locale): string
    {
        return 'eval-'.$locale;
    }

    /**
     * Seed (or refresh) the corpus and return the `documentKey => documentId`
     * map used by the harness.
     *
     * @return array<string, string>
     */
    public function seed(string $tenantId, string $locale, User $actor): array
    {
        $collection = $this->collection($tenantId, $locale, $actor);
        $map = [];

        foreach (KnowledgeEvaluationSuite::corpus($locale) as $document) {
            $path = tempnam(sys_get_temp_dir(), 'ws9eval');
            file_put_contents($path, SyntheticPdf::make(array_column($document['pages'], 'text')));

            try {
                $record = $this->ingestion->ingest($collection, $path, [
                    'title' => $document['title'],
                    'author' => 'LBA Cloud Evaluation',
                    'publication' => 'Synthetic',
                    'license' => 'CC0',
                    'language' => $locale,
                    'original_filename' => $document['key'].'.pdf',
                ], $actor);
            } finally {
                @unlink($path);
            }

            if ($record->status === KnowledgeDocument::STATUS_REVIEW) {
                $this->manager->verify($record, $actor);
                $this->manager->publish($record->refresh(), $actor);
            }

            $this->indexer->reindex($record->refresh());

            $map[$document['key']] = (string) $record->getKey();
        }

        return $map;
    }

    /**
     * Remove the evaluation collection and every row/file it owns.
     */
    public function purge(string $tenantId, string $locale): void
    {
        $collection = KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::TENANT)
            ->where('scope_key', $tenantId)
            ->where('key', self::collectionKey($locale))
            ->first();

        if ($collection === null) {
            return;
        }

        $documents = KnowledgeDocument::query()->where('collection_id', $collection->getKey())->get();

        foreach ($documents as $document) {
            foreach ($document->versions()->get() as $version) {
                Storage::disk((string) ($version->storage_disk ?: 'lba-knowledge'))->delete((string) $version->storage_path);

                KnowledgeExtractedPage::query()->where('document_version_id', $version->getKey())->delete();
            }

            KnowledgeChunk::query()->where('document_id', $document->getKey())->delete();
            KnowledgeDocumentEvent::query()->where('document_id', $document->getKey())->delete();
            KnowledgeDocumentVersion::query()->where('document_id', $document->getKey())->delete();
            $document->delete();
        }

        $collection->delete();
    }

    private function collection(string $tenantId, string $locale, User $actor): KnowledgeCollection
    {
        $existing = KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::TENANT)
            ->where('scope_key', $tenantId)
            ->where('key', self::collectionKey($locale))
            ->first();

        if ($existing instanceof KnowledgeCollection) {
            return $existing;
        }

        return KnowledgeCollectionManager::createTenantCollection(
            $tenantId,
            [
                'key' => self::collectionKey($locale),
                'name' => 'Evaluation Corpus ('.$locale.')',
                'description' => 'Synthetic, non-patient retrieval evaluation corpus.',
                'language' => $locale,
            ],
            $actor,
        );
    }
}
