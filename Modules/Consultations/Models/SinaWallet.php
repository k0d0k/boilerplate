<?php

namespace Modules\Consultations\Models;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workstream 2 — one patient's SINA consultation wallet (CENTRAL database).
 *
 * Belongs to exactly one (tenant, patient). Credits are INTEGER units only;
 * `balance_credits` and `reserved_credits` are separate projections so a
 * reservation moves balance→reserved once and a release/capture moves them back
 * exactly once. This wallet is NOT the tenant AI top-up wallet and the two never
 * share credits.
 */
#[Fillable([
    'tenant_id',
    'patient_id',
    'balance_credits',
    'reserved_credits',
    'lifetime_consumed_credits',
    'status',
    'currency',
    'metadata',
])]
class SinaWallet extends CentralModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'sina_wallets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_credits' => 'integer',
            'reserved_credits' => 'integer',
            'lifetime_consumed_credits' => 'integer',
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
     * @return HasMany<SinaWalletLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SinaWalletLedgerEntry::class, 'wallet_id')->orderByDesc('created_at');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function totalCredits(): int
    {
        return (int) $this->balance_credits + (int) $this->reserved_credits;
    }
}
