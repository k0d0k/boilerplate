<?php

namespace Modules\Consultations\Models;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workstream 2 — one IMMUTABLE SINA wallet ledger entry (CENTRAL database).
 *
 * Written once and never modified (no `updated_at`). `idempotency_key` is
 * UNIQUE, so a repeated reserve/capture/release can never post a second time.
 * `consultation_id` is an opaque tenant-local reference — no message text or
 * clinical content is ever recorded here.
 */
#[Fillable([
    'tenant_id',
    'wallet_id',
    'patient_id',
    'action',
    'credit_units',
    'balance_after',
    'reserved_after',
    'consultation_id',
    'idempotency_key',
    'actor_reference',
    'reason',
])]
class SinaWalletLedgerEntry extends CentralModel
{
    use HasUlids;

    public const ACTION_GRANT = 'grant';

    public const ACTION_RESERVE = 'reserve';

    public const ACTION_CAPTURE = 'capture';

    public const ACTION_RELEASE = 'release';

    public const ACTION_ADJUST = 'adjust';

    protected $table = 'sina_wallet_ledger_entries';

    /** Immutable: an entry has only a creation timestamp. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_units' => 'integer',
            'balance_after' => 'integer',
            'reserved_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SinaWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(SinaWallet::class, 'wallet_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
