<?php

namespace Modules\KnowledgeBase\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WS9.B — one approved, page-aware retrieval chunk (CENTRAL database).
 *
 * The chunk keeps its IMMUTABLE provenance (`document_version_id` +
 * `page_number`), so every citation resolves to the real source page — a page
 * number is never guessed or synthesised. `content_hash` makes reindexing
 * idempotent and `embedding_version` pins the embedding model/version used, so
 * a reindex is exact. The `content` is untrusted document DATA: it is quoted as
 * context at answer time and never treated as an instruction.
 */
#[Fillable([
    'document_id',
    'document_version_id',
    'collection_id',
    'scope',
    'scope_key',
    'page_number',
    'chunk_index',
    'content',
    'char_count',
    'token_estimate',
    'content_hash',
    'embedding_status',
    'embedding_version',
    'embedding',
])]
class KnowledgeChunk extends CentralModel
{
    use HasUlids;

    public const EMBEDDING_PENDING = 'pending';

    public const EMBEDDING_READY = 'ready';

    public const EMBEDDING_FAILED = 'failed';

    protected $table = 'knowledge_chunks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'chunk_index' => 'integer',
            'char_count' => 'integer',
            'token_estimate' => 'integer',
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    /**
     * @return BelongsTo<KnowledgeDocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocumentVersion::class, 'document_version_id');
    }

    public function hasEmbedding(): bool
    {
        return $this->embedding_status === self::EMBEDDING_READY
            && is_array($this->embedding)
            && $this->embedding !== [];
    }
}
