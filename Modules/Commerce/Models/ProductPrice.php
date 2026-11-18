<?php

namespace Modules\Commerce\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A currency-specific price for a product (WS3).
 *
 * Extends {@see TenantModel}. Currency is explicit and limited to the platform
 * currencies (IDR / MYR); a duplicate (product, currency) row is prevented by a
 * unique key.
 */
#[Fillable([
    'product_id',
    'currency',
    'amount',
])]
class ProductPrice extends TenantModel
{
    use HasUlids;

    protected $table = 'product_prices';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
