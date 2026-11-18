<?php

namespace App\Platform\AiBilling\Models;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 — one IMMUTABLE AI wallet ledger entry (CENTRAL database).
 *
 * An entry is written once and never modified (there is no `updated_at`).
 * `idempotency_key` is UNIQUE at the database level, so a repeated
 * reserve/capture/release for the same job/action can never post a second time.
 *
 * `job_reference` is the OPAQUE AI job reference — no patient identity, image
 * or clinical finding is ever recorded here.
 */
#[Fillable([
    'tenant_id',
    'wallet_id',
    'action',
    'credit_units',
    'available_after',
    'reserved_after',
    'job_reference',
    'idempotency_key',
    'actor_reference',
    'reason',
])]
class TenantAiWalletLedgerEntry extends CentralModel
{
    use HasUlids;

    public const ACTION_GRANT = 'grant';

    public const ACTION_RESERVE = 'reserve';

    public const ACTION_CAPTURE = 'capture';

    public const ACTION_RELEASE = 'release';

    public const ACTION_ADJUST = 'adjust';

    protected $table = 'tenant_ai_wallet_ledger_entries';

    /** Immutable: an entry has only a creation timestamp. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_units' => 'integer',
            'available_after' => 'integer',
            'reserved_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TenantAiWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(TenantAiWallet::class, 'wallet_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
