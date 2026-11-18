<?php

namespace App\Models;

use App\Platform\Access\RoleAssignmentGuard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Principal / platform role assignment (Phase 1B-2A).
 *
 * Platform governance roles are not tenant-bound, so they attach directly to an
 * identity. Only platform-scoped roles are valid here; assignments are rejected
 * at creation otherwise (see App\Platform\Access\RoleAssignmentGuard). The status
 * defaults to `pending`, so a privileged assignment never becomes active
 * implicitly and must be explicitly activated.
 */
#[Fillable(['user_id', 'role_id', 'status', 'revoked_at'])]
class PrincipalRoleAssignment extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Reject non-platform roles (and machine-only roles) at creation time, so a
     * privileged or cross-scope assignment can never be persisted.
     */
    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            RoleAssignmentGuard::assertPrincipalAssignable($assignment->role_id);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
