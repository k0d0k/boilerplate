<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimal patient audit trail entry (Phase 3).
 *
 * Append-only: it records the action, the acting central user id and a JSON
 * snapshot of the changed attributes. Deliberately small — the platform keeps
 * only what is needed to answer "who changed this patient, and when".
 */
#[Fillable([
    'patient_id',
    'action',
    'actor_id',
    'changes',
    'occurred_at',
])]
class PatientAuditLog extends TenantModel
{
    use HasUlids;

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_ARCHIVED = 'archived';

    public const ACTION_RESTORED = 'restored';

    public const ACTION_CONSENT_RECORDED = 'consent_recorded';

    public const ACTION_CONSENT_WITHDRAWN = 'consent_withdrawn';

    /** An authorized clinic identity issued a portal invitation proof. */
    public const ACTION_PORTAL_INVITE_ISSUED = 'portal_invite_issued';

    /** A portal account was bound to this patient via a verified invitation. */
    public const ACTION_PORTAL_ACCOUNT_LINKED = 'portal_account_linked';

    /** An authorized clinic identity revoked the portal account binding. */
    public const ACTION_PORTAL_ACCOUNT_UNLINKED = 'portal_account_unlinked';

    protected $table = 'patient_audit_logs';

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
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
