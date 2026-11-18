<?php

namespace Modules\Commerce\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Commerce\Support\Currency;

/**
 * A tenant-local merchant of record (WS8.A).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database and
 * fails closed without a verified tenant context. A merchant declares WHO
 * legally collects money for one currency (and optionally one central brand),
 * the invoice issuer and where the money settles.
 *
 * A merchant NEVER holds a secret — provider credentials remain central and are
 * only ever referenced by name (`settlement_provider`). Archiving/deactivating
 * is non-destructive: only `status` changes.
 */
#[Fillable([
    'brand_id',
    'name',
    'legal_name',
    'currency',
    'country',
    'tax_id',
    'invoice_issuer',
    'settlement_provider',
    'settlement_reference',
    'status',
    'created_by',
    'metadata',
])]
class Merchant extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $table = 'merchants';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'merchant_id');
    }

    /**
     * True when this merchant may settle the given currency (its own declared
     * currency must match exactly — no implicit conversion).
     */
    public function settles(string $currency): bool
    {
        return strtoupper((string) $this->currency) === Currency::assertSupported($currency);
    }

    /**
     * @param  Builder<Merchant>  $query
     * @return Builder<Merchant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
