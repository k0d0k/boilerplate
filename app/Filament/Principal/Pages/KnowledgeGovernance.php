<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Services\KnowledgeCollectionManager;
use Modules\KnowledgeBase\Services\KnowledgeDocumentManager;
use Modules\KnowledgeBase\Services\KnowledgeIndexer;
use Modules\KnowledgeBase\Services\KnowledgeIngestionService;
use Modules\KnowledgeBase\Services\KnowledgeOperations;
use Modules\KnowledgeBase\Support\KnowledgeScope;
use UnitEnum;

/**
 * WS9.D — Principal platform-knowledge governance.
 *
 * Curates the PLATFORM-scope reference knowledge that SINA may use across
 * every opted-in clinic. This page can only ever see and write PLATFORM-scope
 * rows (`scope = platform`, `scope_key = ''`): a tenant's private reference
 * documents are NEVER visible here, and a platform collection is NEVER created
 * from a clinic surface.
 *
 * Publishing is a deliberate human act ({@see KnowledgeDocumentManager::publish()}),
 * revocation purges the retrieval index so a revoked source can never be cited,
 * and reindexing is idempotent. No clinical or patient data flows through this
 * surface — the diagnostics return document provenance only.
 *
 * Every read and write is re-derived server-side from the acting principal's
 * `platform.knowledge.manage` permission; UI visibility is never the boundary.
 */
class KnowledgeGovernance extends Page
{
    use AuthorizesPrincipalResources;
    use WithFileUploads;

    protected static ?string $slug = 'knowledge-governance';

    protected static ?int $navigationSort = 6;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected string $view = 'filament.principal.pages.knowledge-governance';

    // ----- Collection creation -------------------------------------------

    public string $collectionName = '';

    public string $collectionLanguage = 'id';

    public string $collectionDescription = '';

    // ----- Platform PDF ingestion ----------------------------------------

    public ?string $collectionId = null;

    public string $documentTitle = '';

    public string $documentAuthor = '';

    public string $documentPublication = '';

    public string $documentLanguage = '';

    public string $documentLicense = '';

    public string $documentSourceUrl = '';

    /** @var mixed the Livewire temporary upload */
    public $pdf = null;

    /** The document whose version comparison is currently expanded. */
    public ?string $comparingDocumentId = null;

    public static function canAccess(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_KNOWLEDGE_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.principal.knowledge');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.principal.knowledge.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.principal.knowledge.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.principal.knowledge.subtitle');
    }

    /**
     * @return array<string, string>
     */
    public function languageOptions(): array
    {
        return KnowledgeCollectionResource::languageOptions();
    }

    /**
     * Platform-scope collections.
     *
     * @return list<array{id: string, name: string, language: string, documents: int, is_active: bool}>
     */
    public function collections(): array
    {
        return KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::PLATFORM)
            ->where('scope_key', '')
            ->orderBy('name')
            ->get()
            ->map(static fn (KnowledgeCollection $collection): array => [
                'id' => (string) $collection->getKey(),
                'name' => (string) $collection->name,
                'language' => (string) $collection->language,
                'documents' => $collection->documents()->count(),
                'is_active' => (bool) $collection->is_active,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function collectionOptions(): array
    {
        $options = [];

        foreach ($this->collections() as $collection) {
            $options[$collection['id']] = $collection['name'];
        }

        return $options;
    }

    /**
     * Platform-scope documents, newest first, with their indexing status.
     *
     * @return list<array{id: string, title: string, collection: string, status: string, status_key: string, version: int, page_count: int, chunks: int, embedded: int, failed: int, can_index: bool, can_publish: bool, can_archive: bool, can_revoke: bool}>
     */
    public function documents(): array
    {
        $operations = app(KnowledgeOperations::class);

        return KnowledgeDocument::query()
            ->with('collection')
            ->where('scope', KnowledgeScope::PLATFORM)
            ->where('scope_key', '')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(static function (KnowledgeDocument $document) use ($operations): array {
                $status = $operations->status($document);

                return [
                    'id' => (string) $document->getKey(),
                    'title' => (string) $document->title,
                    'collection' => (string) ($document->collection?->name ?? '—'),
                    'status' => __("lba.knowledge.status_{$document->status}"),
                    'status_key' => (string) $document->status,
                    'version' => (int) ($status['version'] ?? 0),
                    'page_count' => $status['page_count'],
                    'chunks' => $status['chunks'],
                    'embedded' => $status['embedded'],
                    'failed' => $status['failed'],
                    'can_index' => $status['extracted'],
                    'can_publish' => $document->status === KnowledgeDocument::STATUS_REVIEW,
                    'can_archive' => $document->status === KnowledgeDocument::STATUS_PUBLISHED,
                    'can_revoke' => $document->status !== KnowledgeDocument::STATUS_REVOKED,
                ];
            })
            ->all();
    }

    /**
     * Immutable version rows for the expanded document, or [].
     *
     * @return list<array{version: int, page_count: int, chunks: int, sha256: string, extracted: bool, created_at: ?string, current: bool}>
     */
    public function versionComparison(): array
    {
        $document = $this->platformDocument($this->comparingDocumentId);

        if (! $document instanceof KnowledgeDocument) {
            return [];
        }

        return app(KnowledgeOperations::class)->versionComparison($document);
    }

    public function toggleVersionComparison(string $documentId): void
    {
        $this->comparingDocumentId = $this->comparingDocumentId === $documentId ? null : $documentId;
    }

    // ----- Mutations ------------------------------------------------------

    public function createCollection(): void
    {
        $this->authorizeManagement();

        $this->validate([
            'collectionName' => ['required', 'string', 'min:2', 'max:160'],
            'collectionLanguage' => ['required', 'string', 'max:12'],
            'collectionDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = static::principalUser();

        KnowledgeCollectionManager::createPlatformCollection([
            'key' => $this->uniqueCollectionKey($this->collectionName),
            'name' => $this->collectionName,
            'description' => $this->collectionDescription !== '' ? $this->collectionDescription : null,
            'language' => $this->collectionLanguage,
            'is_active' => true,
        ], $actor);

        $this->reset(['collectionName', 'collectionDescription']);
        $this->collectionLanguage = 'id';

        Notification::make()->success()->title(__('lba.principal.knowledge.collection_created'))->send();
    }

    public function uploadDocument(): void
    {
        $this->authorizeManagement();

        $this->validate([
            'collectionId' => ['required', 'string'],
            'documentTitle' => ['required', 'string', 'min:2', 'max:255'],
            'documentAuthor' => ['nullable', 'string', 'max:255'],
            'documentPublication' => ['nullable', 'string', 'max:255'],
            'documentLanguage' => ['nullable', 'string', 'max:12'],
            'documentLicense' => ['nullable', 'string', 'max:255'],
            'documentSourceUrl' => ['nullable', 'url', 'max:255'],
            'pdf' => ['required', 'file', 'mimetypes:application/pdf'],
        ]);

        $collection = KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::PLATFORM)
            ->where('scope_key', '')
            ->whereKey($this->collectionId)
            ->first();

        if ($collection === null) {
            $this->addError('collectionId', KnowledgeBaseException::unknownCollection()->getMessage());

            return;
        }

        $file = $this->pdf;

        if (! $file instanceof UploadedFile) {
            $this->addError('pdf', KnowledgeBaseException::unsupportedFileType()->getMessage());

            return;
        }

        $actor = static::principalUser();

        try {
            app(KnowledgeIngestionService::class)->ingest(
                collection: $collection,
                sourcePath: (string) $file->getRealPath(),
                attributes: [
                    'title' => $this->documentTitle,
                    'author' => $this->documentAuthor !== '' ? $this->documentAuthor : null,
                    'publication' => $this->documentPublication !== '' ? $this->documentPublication : null,
                    'language' => $this->documentLanguage !== '' ? $this->documentLanguage : null,
                    'license' => $this->documentLicense !== '' ? $this->documentLicense : null,
                    'source_url' => $this->documentSourceUrl !== '' ? $this->documentSourceUrl : null,
                    'original_filename' => $file->getClientOriginalName(),
                ],
                actor: $actor,
            );
        } catch (KnowledgeBaseException $exception) {
            $this->addError('pdf', $exception->getMessage());

            return;
        }

        $this->reset(['documentTitle', 'documentAuthor', 'documentPublication', 'documentLanguage', 'documentLicense', 'documentSourceUrl', 'pdf']);

        Notification::make()->success()->title(__('lba.principal.knowledge.document_uploaded'))->send();
    }

    public function verifyDocument(string $documentId): void
    {
        $this->runDocumentAction($documentId, function (KnowledgeDocument $document, User $actor): void {
            app(KnowledgeDocumentManager::class)->verify($document, $actor);
        });
    }

    public function publishDocument(string $documentId): void
    {
        $this->runDocumentAction($documentId, function (KnowledgeDocument $document, User $actor): void {
            $manager = app(KnowledgeDocumentManager::class);
            $manager->publish($document, $actor);
            app(KnowledgeIndexer::class)->reindex($document->refresh());
        });
    }

    public function archiveDocument(string $documentId): void
    {
        $this->runDocumentAction($documentId, function (KnowledgeDocument $document, User $actor): void {
            app(KnowledgeDocumentManager::class)->archive($document, $actor);
        });
    }

    public function revokeDocument(string $documentId): void
    {
        $this->runDocumentAction($documentId, function (KnowledgeDocument $document, User $actor): void {
            app(KnowledgeDocumentManager::class)->revoke($document, $actor);
            // Revocation must purge the index so the source can never be cited.
            app(KnowledgeIndexer::class)->purge($document);
        });
    }

    public function reindexDocument(string $documentId): void
    {
        $this->runDocumentAction($documentId, function (KnowledgeDocument $document): void {
            app(KnowledgeOperations::class)->reindex($document);
        }, __('lba.principal.knowledge.reindexed'));
    }

    // ----- internals ------------------------------------------------------

    /**
     * Resolve a PLATFORM-scope document by id, or null. Never resolves a
     * tenant-scope document, so a platform surface can never touch clinic data.
     */
    private function platformDocument(?string $documentId): ?KnowledgeDocument
    {
        if ($documentId === null || $documentId === '') {
            return null;
        }

        return KnowledgeDocument::query()
            ->where('scope', KnowledgeScope::PLATFORM)
            ->where('scope_key', '')
            ->whereKey($documentId)
            ->first();
    }

    /**
     * Run a lifecycle mutation on a PLATFORM-scope document on behalf of the
     * acting principal, surfacing any domain error as a notification.
     */
    private function runDocumentAction(string $documentId, callable $callback, ?string $successTitle = null): void
    {
        $this->authorizeManagement();

        $document = $this->platformDocument($documentId);

        if (! $document instanceof KnowledgeDocument) {
            Notification::make()->danger()->title(__('lba.knowledge.error_not_authorized'))->send();

            return;
        }

        $actor = static::principalUser();

        if ($actor === null) {
            Notification::make()->danger()->title(__('lba.knowledge.error_not_authorized'))->send();

            return;
        }

        try {
            $callback($document, $actor);

            Notification::make()
                ->success()
                ->title($successTitle ?? __('lba.knowledge.action_done'))
                ->send();
        } catch (KnowledgeBaseException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    private function authorizeManagement(): void
    {
        abort_unless(static::principalCan(PermissionKey::PLATFORM_KNOWLEDGE_MANAGE), 403);
    }

    private function uniqueCollectionKey(string $name): string
    {
        $base = Str::slug($name) ?: 'platform-collection';
        $key = $base;
        $suffix = 1;

        while (KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::PLATFORM)
            ->where('scope_key', '')
            ->where('key', $key)
            ->exists()) {
            $key = $base.'-'.(++$suffix);
        }

        return $key;
    }
}
