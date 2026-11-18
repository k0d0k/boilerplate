<?php

namespace Modules\KnowledgeBase\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\KnowledgeBase\Support\KnowledgeScope;

/**
 * WS9.A — a versioned knowledge document (CENTRAL database).
 *
 * The review lifecycle is explicit and one-directional (see {@see self::TRANSITIONS}).
 * A document is NEVER retrievable — and never chunked or embedded for retrieval
 * — until an authorised human PUBLISHES it. `published` is the only status that
 * the retrieval engine will surface.
 */
#[Fillable([
    'collection_id',
    'scope',
    'scope_key',
    'tenant_id',
    'title',
    'author',
    'publication',
    'published_on',
    'license',
    'source_url',
    'language',
    'current_version',
    'status',
    'uploaded_by',
    'verified_by',
    'approved_by',
    'approved_at',
    'published_at',
    'archived_at',
    'revoked_at',
])]
class KnowledgeDocument extends CentralModel
{
    use HasUlids;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_REVIEW = 'review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_REVOKED = 'revoked';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_PROCESSING,
        self::STATUS_REVIEW,
        self::STATUS_PUBLISHED,
        self::STATUS_ARCHIVED,
        self::STATUS_REVOKED,
    ];

    /**
     * The allowed lifecycle transitions.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_UPLOADED => [self::STATUS_PROCESSING, self::STATUS_REVOKED],
        self::STATUS_PROCESSING => [self::STATUS_REVIEW, self::STATUS_REVOKED],
        self::STATUS_REVIEW => [self::STATUS_PUBLISHED, self::STATUS_REVOKED],
        self::STATUS_PUBLISHED => [self::STATUS_ARCHIVED, self::STATUS_REVOKED, self::STATUS_PROCESSING],
        self::STATUS_ARCHIVED => [self::STATUS_REVIEW, self::STATUS_REVOKED],
        self::STATUS_REVOKED => [],
    ];

    protected $table = 'knowledge_documents';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_on' => 'date',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeCollection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCollection::class, 'collection_id');
    }

    /**
     * @return HasMany<KnowledgeDocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeDocumentVersion::class, 'document_id')->orderBy('version');
    }

    /**
     * @return HasMany<KnowledgeDocumentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(KnowledgeDocumentEvent::class, 'document_id')->orderByDesc('occurred_at');
    }

    /**
     * The newest uploaded revision of this document.
     */
    public function currentVersionRecord(): ?KnowledgeDocumentVersion
    {
        return $this->versions()->where('version', $this->current_version)->first();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PUBLISHED => 'success',
            self::STATUS_REVIEW => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_REVOKED => 'danger',
            self::STATUS_ARCHIVED => 'gray',
            default => 'gray',
        };
    }

    public function isPlatformScope(): bool
    {
        return $this->scope === KnowledgeScope::PLATFORM;
    }
}
