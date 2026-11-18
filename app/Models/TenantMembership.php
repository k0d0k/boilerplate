<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tenant membership (Phase 1B-2A): binds one identity to one tenant.
 *
 * Authorization fails closed unless the membership is `active`; revoked,
 * suspended, invited and unknown memberships grant nothing. Tenant roles are
 * attached through this membership only, so a role held in one tenant can never
 * authorize another.
 */
#[Fillable(['user_id', 'tenant_id', 'status', 'revoked_at'])]
class TenantMembership extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_roles', 'membership_id', 'role_id')
            ->using(MembershipRole::class)
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
