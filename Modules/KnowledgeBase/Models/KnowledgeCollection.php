<?php

namespace Modules\KnowledgeBase\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WS9.A — a knowledge collection (CENTRAL database).
 *
 * Groups knowledge documents that share one explicit scope. See the migration
 * for the isolation contract: every row carries `scope` + `scope_key`, so a
 * tenant can only ever see its own tenant-scope collections (or globally
 * published platform collections), never another tenant's.
 */
#[Fillable([
    'scope',
    'scope_key',
    'tenant_id',
    'key',
    'name',
    'description',
    'language',
    'is_active',
    'created_by',
])]
class KnowledgeCollection extends CentralModel
{
    use HasUlids;

    protected $table = 'knowledge_collections';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<KnowledgeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class, 'collection_id');
    }
}
