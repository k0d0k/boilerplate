<?php

namespace Modules\KnowledgeBase\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WS9.A — append-only knowledge document audit (CENTRAL database).
 *
 * Records the action, the acting central user id and a redacted metadata
 * snapshot (no document body, no patient identifier).
 */
#[Fillable([
    'document_id',
    'action',
    'from_status',
    'to_status',
    'actor_id',
    'metadata',
    'occurred_at',
])]
class KnowledgeDocumentEvent extends CentralModel
{
    use HasUlids;

    public const ACTION_UPLOADED = 'uploaded';

    public const ACTION_EXTRACTED = 'extracted';

    public const ACTION_EXTRACTION_FAILED = 'extraction_failed';

    public const ACTION_SUBMITTED = 'submitted';

    public const ACTION_VERIFIED = 'verified';

    public const ACTION_PUBLISHED = 'published';

    public const ACTION_ARCHIVED = 'archived';

    public const ACTION_REVOKED = 'revoked';

    public const ACTION_REVISED = 'revised';

    protected $table = 'knowledge_document_events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
