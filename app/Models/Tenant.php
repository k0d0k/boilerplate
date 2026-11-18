<?php

namespace App\Models;

use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Tenancy\Models\TenantDatabase;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Central tenant registry entry (Phase 1B-2A).
 *
 * A tenant is one independent organisation. Its isolated clinical database is
 * provisioned in a later phase (ARCH-04); this central row only records the
 * organisation and its status so authorization can fail closed when suspended.
 *
 * REMOVAL IS REVERSIBLE (v3.3): "Hapus Mitra" soft-deletes this row, so the
 * partner's access stops at once while its central child rows — including the
 * `tenant_databases` registry row that keeps its physical database name claimed
 * — are preserved. See the soft-deletes migration for why that matters.
 */
#[Fillable(['name', 'slug', 'status', 'metadata'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<Brand, $this>
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'tenant_brands')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * The central registry row describing this tenant's ISOLATED clinical
     * database (Phase 2A). Lifecycle metadata only — never clinical data.
     *
     * @return HasOne<TenantDatabase, $this>
     */
    public function database(): HasOne
    {
        return $this->hasOne(TenantDatabase::class, 'tenant_id');
    }

    /**
     * The tenant's subscription / licence history (WS4). Central data only —
     * never a clinical connection.
     *
     * @return HasMany<TenantSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class, 'tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
