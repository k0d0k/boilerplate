<?php

namespace App\Platform\Subscriptions\Models;

use App\Platform\FeatureGate\Contracts\VerifiesModuleSubscriptions;
use App\Platform\Subscriptions\SubscriptionLicensing;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WS4 — central subscription plan (tenant licence template).
 *
 * A plan is a COMMERCIAL licence definition only: its price, billing interval,
 * trial length, seat limit and the set of modules it licenses. It is never a
 * tenant, brand or branch and holds no clinical data.
 *
 * `module_keys` holds the module keys the plan licenses. The wildcard `*`
 * licenses every installed module. Licensing a tenant with a plan writes
 * subscription-backed entitlements (see {@see SubscriptionLicensing}),
 * which the FeatureGate then verifies through {@see VerifiesModuleSubscriptions}.
 */
#[Fillable([
    'code',
    'name',
    'description',
    'status',
    'price_amount',
    'currency',
    'billing_interval',
    'trial_days',
    'seat_limit',
    'module_keys',
])]
class SubscriptionPlan extends CentralModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    /** Licenses every installed module. */
    public const MODULE_WILDCARD = '*';

    protected $table = 'subscription_plans';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'trial_days' => 'integer',
            'seat_limit' => 'integer',
            'module_keys' => 'array',
        ];
    }

    /**
     * @return HasMany<TenantSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class, 'plan_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return list<string>
     */
    public function moduleKeys(): array
    {
        return array_values(array_filter((array) ($this->module_keys ?? []), 'is_string'));
    }

    /**
     * True when this plan licenses the given module key (exact match or the
     * wildcard). Fail-closed: an empty licence list licenses nothing.
     */
    public function licensesModule(string $moduleKey): bool
    {
        $keys = $this->moduleKeys();

        return in_array(self::MODULE_WILDCARD, $keys, true)
            || in_array($moduleKey, $keys, true);
    }
}
