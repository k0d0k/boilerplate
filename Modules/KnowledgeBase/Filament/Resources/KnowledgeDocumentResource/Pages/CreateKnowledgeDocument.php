<?php

namespace Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource\Pages;

use App\Models\User;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Support\KnowledgeScope;

/**
 * Upload a selectable-text PDF into a TENANT-scope collection of the verified
 * current tenant.
 *
 * The upload is handed to {@see KnowledgeIngestionService}, which verifies the
 * file, de-duplicates by checksum, extracts the ORIGINAL page text, writes the
 * binary to the private disk and moves the document into review. Nothing is
 * retrievable until an authorised human publishes it.
 */
class CreateKnowledgeDocument extends CreateRecord
{
    protected static string $resource = KnowledgeDocumentResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null || $tenantId === '') {
            throw ValidationException::withMessages([
                'data.title' => KnowledgeBaseException::missingContext()->getMessage(),
            ]);
        }

        $collection = KnowledgeCollection::query()->whereKey((string) $data['collection_id'])->first();

        // The collection must belong to the acting tenant's scope; anything else
        // (another tenant's collection, or a platform collection) is rejected.
        if ($collection === null
            || $collection->scope !== KnowledgeScope::TENANT
            || (string) $collection->scope_key !== (string) $tenantId) {
            throw ValidationException::withMessages([
                'data.collection_id' => KnowledgeBaseException::unknownCollection()->getMessage(),
            ]);
        }

        $file = $data['pdf'] ?? null;

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'data.pdf' => KnowledgeBaseException::unsupportedFileType()->getMessage(),
            ]);
        }

        $actor = auth()->user();

        try {
            return KnowledgeDocumentResource::ingestion()->ingest(
                collection: $collection,
                sourcePath: (string) $file->getRealPath(),
                attributes: [
                    'title' => (string) $data['title'],
                    'author' => $data['author'] ?? null,
                    'publication' => $data['publication'] ?? null,
                    'published_on' => $data['published_on'] ?? null,
                    'license' => $data['license'] ?? null,
                    'source_url' => $data['source_url'] ?? null,
                    'language' => (string) ($data['language'] ?? $collection->language),
                    'original_filename' => $file->getClientOriginalName(),
                ],
                actor: $actor instanceof User ? $actor : null,
            );
        } catch (KnowledgeBaseException $exception) {
            throw ValidationException::withMessages([
                'data.pdf' => $exception->getMessage(),
            ]);
        }
    }
}
