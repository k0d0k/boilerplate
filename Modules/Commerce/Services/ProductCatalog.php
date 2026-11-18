<?php

namespace Modules\Commerce\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — product catalog lifecycle.
 *
 * Every mutation runs inside a transaction against the VERIFIED tenant database
 * (see {@see RunsVerifiedTenantTransactions}); a product can therefore never be
 * written to the central database or another tenant's database.
 *
 * The service owns three pieces of catalog integrity:
 *  - a stable, tenant-unique SKU and slug (generated when the caller leaves them
 *    blank, never silently overwriting a supplied value);
 *  - the currency of a price is always explicit and limited to the supported
 *    currencies;
 *  - archiving is non-destructive (status only), so historical orders that
 *    reference the product stay intact.
 */
final class ProductCatalog
{
    use RunsVerifiedTenantTransactions;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Product
    {
        return $this->tenantTransaction(function () use ($attributes): Product {
            $product = new Product;
            $product->fill($attributes);

            $product->name = trim((string) $product->name);
            $product->slug = $this->uniqueSlug((string) ($product->slug ?: $product->name));
            $product->sku = $this->uniqueSku((string) ($product->sku ?: $this->generateSku($product)));

            if (! in_array($product->status, Product::STATUSES, true)) {
                $product->status = Product::STATUS_ACTIVE;
            }

            if (! in_array($product->kind, Product::KINDS, true)) {
                $product->kind = Product::KIND_PRODUCT;
            }

            $product->base_currency = Currency::assertSupported((string) ($product->base_currency ?: Currency::IDR));

            $product->save();

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        return $this->tenantTransaction(function () use ($product, $attributes): Product {
            $product->fill($attributes);

            if (filled($product->name) && blank($product->slug)) {
                $product->slug = $this->uniqueSlug((string) $product->name, $product->getKey());
            }

            if (filled($product->base_currency)) {
                $product->base_currency = Currency::assertSupported((string) $product->base_currency);
            }

            $product->save();

            return $product;
        });
    }

    /**
     * Non-destructive archiving: the product row is retained so historical
     * orders keep resolving it; only the status changes.
     */
    public function archive(Product $product): Product
    {
        return $this->tenantTransaction(function () use ($product): Product {
            $product->forceFill(['status' => Product::STATUS_ARCHIVED])->save();

            return $product;
        });
    }

    /**
     * Set (or replace) a currency-specific price.
     */
    public function setPrice(Product $product, string $currency, float $amount): void
    {
        $currency = Currency::assertSupported($currency);

        $this->tenantTransaction(function () use ($product, $currency, $amount): void {
            $product->prices()->updateOrCreate(
                ['currency' => $currency],
                ['amount' => round($amount, Currency::decimals($currency))],
            );
        });
    }

    private function uniqueSlug(string $value, ?string $ignoreId = null): string
    {
        $base = Str::slug($value);
        $base = $base !== '' ? $base : 'product';
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?string $ignoreId): bool
    {
        $query = Product::query()->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }

    private function uniqueSku(string $value): string
    {
        $base = Str::upper(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '');
        $base = trim($base, '-');
        $base = $base !== '' ? $base : 'SKU';
        $sku = $base;
        $suffix = 2;

        while (Product::query()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.$suffix;
            $suffix++;
        }

        return $sku;
    }

    private function generateSku(Product $product): string
    {
        return 'SKU-'.Str::upper((string) Str::ulid()->toBase32());
    }
}
