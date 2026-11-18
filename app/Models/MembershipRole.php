<?php

namespace App\Models;

use App\Platform\Access\RoleAssignmentGuard;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Membership -> role pivot (Phase 1B-2A).
 *
 * Enforces strict role scope at assignment time: only tenant/patient roles may
 * be attached through a membership. Principal/platform roles and the machine-only
 * `ai_service` role are rejected, so a cross-scope role can never be persisted
 * through `attach()` regardless of the UI.
 */
class MembershipRole extends Pivot
{
    protected $table = 'membership_roles';

    public $incrementing = false;

    public $timestamps = true;

    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            RoleAssignmentGuard::assertMembershipAssignable($pivot->role_id);
        });
    }
}
