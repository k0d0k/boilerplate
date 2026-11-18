<?php

namespace Modules\Practitioners\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only practitioner audit trail entry (WS7.A).
 *
 * Records the action, the acting central user id and a compact JSON snapshot of
 * the change. Deliberately small: it answers "who changed this practitioner,
 * and when" without copying sensitive credential data.
 */
#[Fillable([
    'practitioner_id',
    'action',
    'actor_id',
    'changes',
    'occurred_at',
])]
class PractitionerAuditLog extends TenantModel
{
    use HasUlids;

    public const ACTION_REGISTERED = 'registered';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_VERIFIED = 'verified';

    public const ACTION_SUSPENDED = 'suspended';

    public const ACTION_REACTIVATED = 'reactivated';

    public const ACTION_ARCHIVED = 'archived';

    public const ACTION_CREDENTIAL_ADDED = 'credential_added';

    public const ACTION_CREDENTIAL_VERIFIED = 'credential_verified';

    public const ACTION_CREDENTIAL_REJECTED = 'credential_rejected';

    public const ACTION_CREDENTIAL_REVOKED = 'credential_revoked';

    public const ACTION_BRANCH_AUTHORIZED = 'branch_authorized';

    public const ACTION_BRANCH_REVOKED = 'branch_revoked';

    public const ACTION_SCHEDULE_CREATED = 'schedule_created';

    public const ACTION_SCHEDULE_UPDATED = 'schedule_updated';

    public const ACTION_SCHEDULE_REMOVED = 'schedule_removed';

    public const ACTION_EXCEPTION_RECORDED = 'exception_recorded';

    public const ACTION_EXCEPTION_CANCELLED = 'exception_cancelled';

    protected $table = 'practitioner_audit_logs';

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
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'practitioner_id');
    }
}
