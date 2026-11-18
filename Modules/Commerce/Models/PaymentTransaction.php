<?php

namespace Modules\Commerce\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only payment transaction (WS3).
 *
 * Every money movement against an order — a POS cash tender, a manual transfer,
 * or a hosted gateway charge — is recorded here. The row is never overwritten:
 * a failed attempt stays as history and a new attempt is appended.
 *
 * `idempotency_key` is UNIQUE and `[provider, provider_reference]` is UNIQUE, so
 * a retried request or a duplicated gateway webhook can only ever produce ONE
 * transaction — a duplicate payment cannot be recorded.
 */
#[Fillable([
    'order_id',
    'invoice_id',
    'provider',
    'provider_reference',
    'method',
    'currency',
    'amount',
    'status',
    'idempotency_key',
    'request_payload',
    'response_payload',
    'paid_at',
    'metadata',
])]
class PaymentTransaction extends TenantModel
{
    use HasUlids;

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_INITIATED,
        self::STATUS_PENDING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
        self::STATUS_REFUNDED,
    ];

    /** @var list<string> */
    public const SETTLED_STATUSES = [
        self::STATUS_SUCCEEDED,
    ];

    protected $table = 'payment_transactions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }
}
