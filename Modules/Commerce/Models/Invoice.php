<?php

namespace Modules\Commerce\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant invoice (WS3).
 *
 * Records what an order owes and how it was settled. An invoice is issued, then
 * either paid or voided — never deleted, so the financial trail is preserved.
 */
#[Fillable([
    'order_id',
    'number',
    'currency',
    'amount',
    'status',
    'issued_at',
    'due_at',
    'paid_at',
    'metadata',
])]
class Invoice extends TenantModel
{
    use HasUlids;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_PAID,
        self::STATUS_VOID,
    ];

    protected $table = 'invoices';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
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
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'invoice_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
