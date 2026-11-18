<?php

namespace App\Platform\Branches\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant-scoped assignment of a central user to a branch (Phase 2B).
 *
 * Lives in the tenant database, so it can only ever reference a branch of that
 * tenant. `user_id` is the id of a CENTRAL user and carries no cross-database
 * foreign key (central and tenant databases are separate connections). The
 * user's active central membership and role are validated in the application
 * layer before an assignment is written or honoured.
 *
 * One staff member may hold several assignments (one per branch). Revocation is
 * non-destructive.
 */
#[Fillable(['branch_id', 'user_id', 'status'])]
class BranchStaffAssignment extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'branch_staff_assignments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
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
     * @param  Builder<BranchStaffAssignment>  $query
     * @return Builder<BranchStaffAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
