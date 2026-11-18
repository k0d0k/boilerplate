<?php

namespace Modules\KnowledgeBase\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WS9.A — extracted text of one ORIGINAL PDF page (CENTRAL database).
 *
 * `page_number` is the true 1-based page in the source PDF, so every downstream
 * chunk and citation can point at the real page. A scanned page keeps an empty
 * `content`; the extractor never invents text.
 */
#[Fillable([
    'document_version_id',
    'page_number',
    'content',
    'char_count',
])]
class KnowledgeExtractedPage extends CentralModel
{
    use HasUlids;

    protected $table = 'knowledge_extracted_pages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'char_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeDocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocumentVersion::class, 'document_version_id');
    }
}
