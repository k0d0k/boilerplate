<?php

namespace Modules\KnowledgeBase\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WS9.A — an immutable knowledge document version (CENTRAL database).
 *
 * One row per uploaded PDF revision. The bytes are held on a PRIVATE disk
 * (opaque storage path only, no public URL); `sha256` de-duplicates by scope and
 * `page_count` is the ACTUAL extracted page count.
 */
#[Fillable([
    'document_id',
    'version',
    'scope',
    'scope_key',
    'sha256',
    'byte_size',
    'page_count',
    'language',
    'mime',
    'original_filename',
    'storage_disk',
    'storage_path',
    'extraction_status',
    'extraction_error',
    'created_by',
])]
class KnowledgeDocumentVersion extends CentralModel
{
    use HasUlids;

    public const EXTRACTION_PENDING = 'pending';

    public const EXTRACTION_EXTRACTED = 'extracted';

    public const EXTRACTION_FAILED = 'failed';

    protected $table = 'knowledge_document_versions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'page_count' => 'integer',
            'version' => 'integer',
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
     * @return HasMany<KnowledgeExtractedPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(KnowledgeExtractedPage::class, 'document_version_id')->orderBy('page_number');
    }

    /**
     * @return HasMany<KnowledgeChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_version_id')->orderBy('chunk_index');
    }

    public function isExtracted(): bool
    {
        return $this->extraction_status === self::EXTRACTION_EXTRACTED;
    }
}
