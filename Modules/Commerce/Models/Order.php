<?php

namespace Modules\Commerce\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\PaymentSettlement;
use Modules\Commerce\Support\Currency;
use Modules\Patients\Models\Patient;

/**
 * A tenant-local order (WS3).
 *
 * Extends {@see TenantModel}, so it resolves the active tenant database and
 * fails closed without a verified tenant context. An order belongs to one
 * branch and carries an explicit currency and denormalised totals.
 *
 * Lifecycle: `pending` → `paid` (fully settled) or `partially_paid` →
 * `cancelled` / `refunded`. Money can only move through {@see OrderService}
 * and {@see PaymentSettlement}, which keep totals and
 * status consistent.
 */
#[Fillable([
    'order_number',
    'idempotency_key',
    'source',
    'branch_id',
    'patient_id',
    'merchant_id',
    'customer_name',
    'customer_email',
    'customer_phone',
    'currency',
    'discount_total',
    'tax_total',
    'status',
    'fulfillment_status',
    'placed_by',
    'placed_at',
    'notes',
    'metadata',
])]
class Order extends TenantModel
{
    use HasUlids;

    public const SOURCE_POS = 'pos';

    public const SOURCE_ONLINE = 'online';

    public const SOURCE_API = 'api';

    /** @var list<string> */
    public const SOURCES = [
        self::SOURCE_POS,
        self::SOURCE_ONLINE,
        self::SOURCE_API,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    public const FULFILLMENT_PENDING = 'pending';

    public const FULFILLMENT_FULFILLED = 'fulfilled';

    public const FULFILLMENT_CANCELLED = 'cancelled';

    public const FULFILLMENT_RETURNED = 'returned';

    /** @var list<string> */
    public const FULFILLMENT_STATUSES = [
        self::FULFILLMENT_PENDING,
        self::FULFILLMENT_FULFILLED,
        self::FULFILLMENT_CANCELLED,
        self::FULFILLMENT_RETURNED,
    ];

    protected $table = 'orders';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'placed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'branch_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'order_id');
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'order_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    /**
     * Whether the order can still be settled (money may still be taken).
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PARTIALLY_PAID], true);
    }

    public function outstanding(): float
    {
        return round((float) $this->grand_total - (float) $this->paid_total, Currency::decimals((string) $this->currency));
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIALLY_PAID]);
    }
}
