<?php

namespace App\Platform\AiBilling\Models;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 6 — one tenant's central AI credit wallet.
 *
 * Lives in the CENTRAL database and belongs to exactly ONE tenant. Credits are
 * INTEGER units only. `available_credits` and `reserved_credits` are separate
 * projections: reserve moves available→reserved once, capture draws reserved
 * down and increments `lifetime_consumed_credits`, release returns
 * reserved→available once. Available can never go negative.
 *
 * The wallet is the TENANT AI top-up wallet; it is NOT the patient SINA
 * consultation wallet and the two never share credits.
 */
#[Fillable([
    'tenant_id',
    'available_credits',
    'reserved_credits',
    'lifetime_consumed_credits',
    'status',
    'currency',
    'metadata',
])]
class TenantAiWallet extends CentralModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    protected $table = 'tenant_ai_wallets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available_credits' => 'integer',
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
     * @return HasMany<TenantAiWalletLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(TenantAiWalletLedgerEntry::class, 'wallet_id')->orderByDesc('created_at');
    }

    /**
     * @return HasMany<AiUsageRecord, $this>
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(AiUsageRecord::class, 'wallet_id')->orderByDesc('recorded_at');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The total credits currently owned by the wallet (reserved + available).
     */
    public function totalCredits(): int
    {
        return (int) $this->available_credits + (int) $this->reserved_credits;
    }
}
