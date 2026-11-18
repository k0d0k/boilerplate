<?php

namespace Modules\KnowledgeBase\Services;

use App\Models\User;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Support\KnowledgeScope;

/**
 * WS9.A — the write path for knowledge collections.
 *
 * A collection's SCOPE and SCOPE KEY are always set from a SERVER-derived
 * value, never from form input: a Clinic Admin creates TENANT-scope collections
 * for their own tenant only, and a platform-global collection is only ever
 * created through the Principal path (WS9.D). Cross-scope promotion is therefore
 * impossible from a clinic form.
 */
final class KnowledgeCollectionManager
{
    /**
     * @param  array{key: string, name: string, description?: ?string, language?: string, is_active?: bool}  $attributes
     */
    public static function createTenantCollection(
        string $tenantId,
        array $attributes,
        ?User $actor = null,
    ): KnowledgeCollection {
        if ($tenantId === '') {
            throw KnowledgeBaseException::missingContext();
        }

        return KnowledgeCollection::query()->create([
            'scope' => KnowledgeScope::TENANT,
            'scope_key' => $tenantId,
            'tenant_id' => $tenantId,
            'key' => $attributes['key'],
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'language' => $attributes['language'] ?? 'id',
            'is_active' => $attributes['is_active'] ?? true,
            'created_by' => $actor?->getKey(),
        ]);
    }

    /**
     * Create a PLATFORM-scope collection (globally published reference). Only
     * reachable from the Principal governance path, which enforces the
     * `platform.knowledge.manage` permission before calling this.
     *
     * @param  array{key: string, name: string, description?: ?string, language?: string, is_active?: bool}  $attributes
     */
    public static function createPlatformCollection(
        array $attributes,
        ?User $actor = null,
    ): KnowledgeCollection {
        return KnowledgeCollection::query()->create([
            'scope' => KnowledgeScope::PLATFORM,
            'scope_key' => '',
            'tenant_id' => null,
            'key' => $attributes['key'],
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'language' => $attributes['language'] ?? 'id',
            'is_active' => $attributes['is_active'] ?? true,
            'created_by' => $actor?->getKey(),
        ]);
    }
}
