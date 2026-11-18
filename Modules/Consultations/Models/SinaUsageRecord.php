<?php

namespace Modules\Consultations\Models;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workstream 2 — one immutable SINA usage record (CENTRAL database).
 *
 * Written once per CAPTURED billable SINA turn, never per request, so a
 * completed turn is metered exactly once. `idempotency_key` is UNIQUE. Only
 * non-identifying accounting metadata is stored — provider/model, integer token
 * counters and the charged credits; never message text or a patient identity.
 */
#[Fillable([
    'tenant_id',
    'wallet_id',
    'patient_id',
    'consultation_id',
    'provider',
    'model_name',
    'prompt_tokens',
    'completion_tokens',
    'credit_units',
    'idempotency_key',
    'recorded_at',
])]
class SinaUsageRecord extends CentralModel
{
    use HasUlids;

    protected $table = 'sina_usage_records';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'credit_units' => 'integer',
            'recorded_at' => 'datetime',
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
