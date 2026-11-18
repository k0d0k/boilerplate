<?php

namespace Modules\Commerce\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Services\OrderService;

/**
 * One line of a tenant order (WS3).
 *
 * A line snapshots the product name, SKU and unit price at the moment of sale
 * so the historical order never changes when the catalog does. It is created
 * only by {@see OrderService} and is deleted
 * alongside its order.
 */
#[Fillable([
    'order_id',
    'product_id',
    'name',
    'sku',
    'unit_price',
    'quantity',
    'line_total',
    'currency',
    'metadata',
])]
class OrderItem extends TenantModel
{
    use HasUlids;

    protected $table = 'order_items';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'line_total' => 'decimal:2',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
