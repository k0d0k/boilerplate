<?php

namespace Modules\Practitioners\Models;

use App\Platform\Branches\Models\BranchStaffAssignment;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch a practitioner is authorized to practise at (WS7.A).
 *
 * Extends {@see TenantModel}, so it resolves the verified tenant database at
 * query time and fails closed without one: an authorization can never reference
 * another tenant's branch or practitioner.
 *
 * Authorization is a clinical, tenant-local decision distinct from the platform
 * staff branch assignment ({@see BranchStaffAssignment}):
 * it says which branches a verified practitioner may be booked into. Revocation
 * is non-destructive — the row is retained (`status` = revoked) so history is
 * preserved.
 */
#[Fillable(['branch_id', 'status'])]
class PractitionerBranchAuthorization extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'practitioner_branch_authorizations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'practitioner_id');
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'branch_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @param  Builder<PractitionerBranchAuthorization>  $query
     * @return Builder<PractitionerBranchAuthorization>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
