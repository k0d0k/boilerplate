<?php

namespace Modules\Commerce\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Commerce\Support\Currency;

/**
 * A tenant-local sellable product (WS3).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at query
 * time and fails closed without a verified tenant context.
 *
 * A product carries an optional central `brand_id` label (a commercial identity,
 * never a tenant or branch) and a `base_currency` + `base_price`. Currency is
 * always explicit; additional per-currency prices live in {@see ProductPrice}.
 *
 * Archiving is non-destructive — only `status` changes.
 */
#[Fillable([
    'brand_id',
    'category_id',
    'sku',
    'slug',
    'name',
    'description',
    'unit',
    'kind',
    'base_currency',
    'base_price',
    'track_inventory',
    'status',
    'metadata',
])]
class Product extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_ARCHIVED,
    ];

    public const KIND_PRODUCT = 'product';

    public const KIND_SERVICE = 'service';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_PRODUCT,
        self::KIND_SERVICE,
    ];

    protected $table = 'products';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'track_inventory' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'product_id');
    }

    /**
     * @return HasMany<InventoryStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'product_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * The selling price for an explicit currency: the currency-specific price
     * when one is defined, otherwise the base price when the base currency
     * matches. Returns null when the product is not priced in that currency, so
     * a caller can never silently sell at the wrong price.
     */
    public function priceFor(string $currency): ?float
    {
        $currency = Currency::assertSupported($currency);

        /** @var ProductPrice|null $price */
        $price = $this->relationLoaded('prices')
            ? $this->prices->firstWhere('currency', $currency)
            : $this->prices()->where('currency', $currency)->first();

        if ($price !== null) {
            return (float) $price->amount;
        }

        if (strtoupper((string) $this->base_currency) === $currency) {
            return (float) $this->base_price;
        }

        return null;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
