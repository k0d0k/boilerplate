<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Access\PermissionKey;
use App\Platform\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — patient product catalog.
 *
 * Exposes the bound clinic's ACTIVE catalog to the patient app. A product's
 * price is always returned for ONE explicitly requested currency (default IDR),
 * never guessed: a product that cannot be priced in that currency is returned
 * with `price: null` and `available: false`. Requires `order.own`.
 */
final class ShopController extends PatientApiController
{
    use PresentsPatientApi;

    public function index(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::ORDER_OWN);

        $currency = $this->currency($request);

        $paginator = Product::query()
            ->active()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.(string) $request->query('search').'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->through(fn (Product $product) => $this->productArray($product, $currency));

        return ApiResponse::paginated($paginator);
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $this->auth->authorize(PermissionKey::ORDER_OWN);

        $currency = $this->currency($request);

        $model = Product::query()->active()->whereKey($product)->first();

        abort_if($model === null, 404);

        return ApiResponse::ok($this->productArray($model, $currency));
    }

    private function currency(Request $request): string
    {
        $currency = strtoupper((string) $request->query('currency', Currency::IDR));

        if (! Currency::isSupported($currency)) {
            throw ValidationException::withMessages(['currency' => 'Unsupported currency.']);
        }

        return $currency;
    }
}
