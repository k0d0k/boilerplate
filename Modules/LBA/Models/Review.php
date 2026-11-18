<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7B — one human review decision on an assessment (TENANT database).
 *
 * A review is APPEND-ONLY: every decision (return for changes / approve /
 * reject) is recorded with the reviewing identity, an identity snapshot and the
 * decision time. Rows are never updated or deleted, so the human-review history
 * of an examination stays fully auditable.
 *
 * Only a human reviewer produces a review row. Nothing in the platform — and no
 * AI process — may write here automatically; a report can never be approved
 * without an explicit, attributable human action.
 */
#[Fillable([
    'assessment_id',
    'reviewer_id',
    'reviewer_snapshot',
    'decision',
    'notes',
    'decided_at',
])]
class Review extends TenantModel
{
    use HasUlids;

    public const DECISION_RETURNED_FOR_CHANGES = 'returned_for_changes';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    /** @var list<string> */
    public const DECISIONS = [
        self::DECISION_RETURNED_FOR_CHANGES,
        self::DECISION_APPROVED,
        self::DECISION_REJECTED,
    ];

    protected $table = 'lba_reviews';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewer_snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function isApproval(): bool
    {
        return $this->decision === self::DECISION_APPROVED;
    }
}
