<?php

namespace Modules\Consultations\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimal consultation audit trail entry (Workstream 2).
 *
 * Append-only: it records the action, the acting central user id and a JSON
 * snapshot of the changed attributes. Deliberately small — the platform keeps
 * only what is needed to answer "who acted on this consultation, and when".
 */
#[Fillable([
    'consultation_id',
    'action',
    'actor_id',
    'changes',
    'occurred_at',
])]
class ConsultationAuditLog extends TenantModel
{
    use HasUlids;

    public const ACTION_CREATED = 'created';

    public const ACTION_SCHEDULED = 'scheduled';

    public const ACTION_STATUS_CHANGED = 'status_changed';

    public const ACTION_MESSAGE_SENT = 'message_sent';

    public const ACTION_SINA_TURN = 'sina_turn';

    protected $table = 'consultation_audit_logs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Consultation, $this>
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }
}
