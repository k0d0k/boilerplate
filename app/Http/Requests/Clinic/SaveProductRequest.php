<?php

namespace App\Http\Requests\Clinic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Commerce\Filament\Resources\ProductResource;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Models\ProductCategory;
use Modules\Commerce\Services\ProductCatalog;
use Modules\Commerce\Support\Currency;

/**
 * TailAdmin product catalog create / update (WS3).
 *
 * A product is a tenant-owned row, so every rule is validated against the
 * VERIFIED tenant database (the {@see Product} model resolves that connection
 * and fails closed without it). Only an identity holding `commerce.manage` in
 * the verified context (and the commerce module entitlement) may write; the
 * acting tenant is never taken from the request body.
 *
 * The SKU and slug are left to {@see ProductCatalog}
 * so tenant-unique values are generated when the caller leaves them blank.
 * Prices are always priced in an explicit, supported currency.
 */
final class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ProductResource::canViewAny();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'string', 'max:64', Rule::exists(ProductCategory::class, 'id')],
            'kind' => ['required', Rule::in(Product::KINDS)],
            'unit' => ['nullable', 'string', 'max:20'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_currency' => ['required', Rule::in(Currency::SUPPORTED)],
            'base_price' => ['required', 'numeric', 'min:0'],
            'track_inventory' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['track_inventory' => $this->boolean('track_inventory')]);
    }
}
