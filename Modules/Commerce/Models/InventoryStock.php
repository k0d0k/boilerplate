<?php

namespace Modules\Commerce\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Commerce\Services\InventoryService;

/**
 * Branch-level stock for one product (WS3).
 *
 * Extends {@see TenantModel}. A stock row is only ever mutated through
 * {@see InventoryService}, which refuses to drive the
 * quantity below zero; `fillable` therefore excludes `quantity`.
 */
#[Fillable([
    'product_id',
    'branch_id',
    'reorder_level',
])]
class InventoryStock extends TenantModel
{
    use HasUlids;

    protected $table = 'inventory_stocks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reorder_level' => 'integer',
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

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id', 'product_id');
    }

    public function isLow(): bool
    {
        return $this->reorder_level !== null && $this->quantity <= (int) $this->reorder_level;
    }
}
