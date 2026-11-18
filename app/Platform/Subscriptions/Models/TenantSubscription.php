<?php

namespace App\Platform\Subscriptions\Models;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * WS4 — one tenant's subscription / licence (central).
 *
 * Binds exactly ONE tenant to ONE plan for a period. Central data only: the
 * row never reaches a tenant clinical database. `reference` is the opaque token
 * recorded on subscription-backed module entitlements and verified by the
 * FeatureGate, so access is never inferred from client input.
 *
 * An inactive, cancelled, ended or expired subscription fails closed: the
 * FeatureGate denies any module that requires a subscription.
 */
#[Fillable([
    'reference',
    'tenant_id',
    'plan_id',
    'status',
    'seats',
    'starts_at',
    'trial_ends_at',
    'current_period_start',
    'current_period_end',
    'cancelled_at',
    'ended_at',
    'metadata',
])]
class TenantSubscription extends CentralModel
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'tenant_subscriptions';

    protected static function booted(): void
    {
        static::creating(function (self $subscription): void {
            if ($subscription->reference === null || $subscription->reference === '') {
                $subscription->reference = 'sub_'.Str::ulid()->toBase32();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * A live subscription: trialing/active, not cancelled or ended, and within
     * its current period. Fail-closed on every other state.
     */
    public function isActive(): bool
    {
        if ($this->cancelled_at !== null || $this->ended_at !== null) {
            return false;
        }

        if (! in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIALING], true)) {
            return false;
        }

        return $this->current_period_end === null || $this->current_period_end->isFuture();
    }
}
