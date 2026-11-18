<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7A — append-only assessment audit trail (TENANT database).
 *
 * Every meaningful action — creation, an indicator change, a status transition,
 * an evidence link/unlink, a program-phase or brand selection, a draft save —
 * is appended with the acting identity and timestamp. Rows are never updated or
 * deleted, so the assessment lifecycle stays auditable.
 */
#[Fillable([
    'assessment_id',
    'action',
    'from_status',
    'to_status',
    'indicator_key',
    'actor_id',
    'note',
    'occurred_at',
])]
class AssessmentEvent extends TenantModel
{
    use HasUlids;

    public const ACTION_CREATED = 'created';

    public const ACTION_INDICATOR_SET = 'indicator_set';

    public const ACTION_NOTES_UPDATED = 'notes_updated';

    public const ACTION_SUMMARY_NOTES_UPDATED = 'summary_notes_updated';

    public const ACTION_EVIDENCE_LINKED = 'evidence_linked';

    public const ACTION_EVIDENCE_UNLINKED = 'evidence_unlinked';

    public const ACTION_PROGRAM_PHASE_SET = 'program_phase_set';

    public const ACTION_BRAND_SELECTED = 'brand_selected';

    public const ACTION_DRAFT_SAVED = 'draft_saved';

    public const ACTION_STATUS_CHANGED = 'status_changed';

    protected $table = 'lba_assessment_events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }
}
