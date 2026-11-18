<?php

namespace Modules\Commerce\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable, append-only stock movement (WS3).
 *
 * Extends {@see TenantModel}. A movement records the signed delta applied to a
 * branch's stock and the resulting balance. Rows are only ever created — never
 * updated or deleted — so the ledger stays a faithful history.
 */
#[Fillable([
    'product_id',
    'branch_id',
    'type',
    'quantity',
    'balance_after',
    'reason',
    'reference_type',
    'reference_id',
    'actor_id',
    'recorded_at',
])]
class StockMovement extends TenantModel
{
    use HasUlids;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_SALE = 'sale';

    public const TYPE_REFUND = 'refund';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_RECEIPT,
        self::TYPE_ISSUE,
        self::TYPE_ADJUSTMENT,
        self::TYPE_SALE,
        self::TYPE_REFUND,
        self::TYPE_TRANSFER_OUT,
        self::TYPE_TRANSFER_IN,
    ];

    protected $table = 'stock_movements';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'branch_id');
    }
}
