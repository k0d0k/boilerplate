<?php

namespace Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Services\KnowledgeCollectionManager;
use Modules\KnowledgeBase\Support\KnowledgeScope;

/**
 * Create a TENANT-scope knowledge collection for the verified current tenant.
 *
 * The scope and scope key are set from the SERVER-derived tenant — never from
 * the form — so a Clinic Admin can never promote content to platform scope.
 */
class CreateKnowledgeCollection extends CreateRecord
{
    protected static string $resource = KnowledgeCollectionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenantId = KnowledgeCollectionResource::currentTenantId();

        if ($tenantId === null) {
            throw ValidationException::withMessages([
                'data.name' => KnowledgeBaseException::missingContext()->getMessage(),
            ]);
        }

        $actor = auth()->user();

        try {
            return KnowledgeCollectionManager::createTenantCollection(
                tenantId: $tenantId,
                attributes: [
                    'key' => $this->uniqueKey($tenantId, (string) $data['name']),
                    'name' => (string) $data['name'],
                    'description' => $data['description'] ?? null,
                    'language' => (string) ($data['language'] ?? 'id'),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ],
                actor: $actor instanceof User ? $actor : null,
            );
        } catch (KnowledgeBaseException $exception) {
            throw ValidationException::withMessages([
                'data.name' => $exception->getMessage(),
            ]);
        }
    }

    private function uniqueKey(string $tenantId, string $name): string
    {
        $base = Str::slug($name) ?: 'collection';
        $key = $base;
        $suffix = 1;

        while (KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::TENANT)
            ->where('scope_key', $tenantId)
            ->where('key', $key)
            ->exists()) {
            $key = $base.'-'.(++$suffix);
        }

        return $key;
    }
}
