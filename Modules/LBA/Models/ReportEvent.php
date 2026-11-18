<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7B — append-only report audit trail (TENANT database).
 *
 * Records every report lifecycle action (generated / approved / revised /
 * previewed / downloaded) together with the acting identity and time. Rows are
 * never updated or deleted, so who generated, saw or downloaded a patient-facing
 * document stays provable.
 */
#[Fillable([
    'assessment_id',
    'report_version_id',
    'action',
    'actor_id',
    'note',
    'occurred_at',
])]
class ReportEvent extends TenantModel
{
    use HasUlids;

    public const ACTION_GENERATED = 'generated';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_RETURNED = 'returned';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_REVISED = 'revised';

    public const ACTION_RELEASED = 'released';

    public const ACTION_PREVIEWED = 'previewed';

    public const ACTION_DOWNLOADED = 'downloaded';

    protected $table = 'lba_report_events';

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
