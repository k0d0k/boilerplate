<?php

namespace App\Platform\AiBilling\Models;

use App\Models\Tenant;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 — one immutable AI usage record (CENTRAL database).
 *
 * Written once per CAPTURED billable inference, never per request, so a
 * completed job is billed exactly once. `idempotency_key` is UNIQUE.
 *
 * Only non-identifying accounting metadata is stored — opaque job reference,
 * provider/model, price snapshot and integer units. No patient identity, no
 * image and no clinical finding is ever recorded centrally.
 */
#[Fillable([
    'tenant_id',
    'wallet_id',
    'job_reference',
    'provider',
    'model_name',
    'model_version',
    'pricing_rule_ref',
    'unit_price_credits',
    'credit_units',
    'idempotency_key',
    'recorded_at',
])]
class AiUsageRecord extends CentralModel
{
    use HasUlids;

    protected $table = 'ai_usage_records';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_credits' => 'integer',
            'credit_units' => 'integer',
            'recorded_at' => 'datetime',
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
