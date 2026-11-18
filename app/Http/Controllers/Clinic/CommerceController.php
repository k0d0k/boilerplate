<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\SaveProductRequest;
use App\Models\User;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Commerce\Exceptions\InventoryException;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Filament\Resources\ProductResource;
use Modules\Commerce\Filament\Resources\StockLevelResource;
use Modules\Commerce\Models\InventoryStock;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Models\ProductCategory;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Modules\Commerce\Services\RefundService;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — TailAdmin Commerce surfaces for the Clinic panel.
 *
 * The Blade counterparts of {@see ProductResource}, {@see StockLevelResource}
 * and {@see OrderResource}: the catalog, branch stock and the order book are
 * managed here through the SAME domain services
 * ({@see ProductCatalog}, {@see InventoryService}, {@see OrderService},
 * {@see RefundService}, {@see PaymentManager}), so no money or stock path is
 * duplicated.
 *
 * Every action re-derives on the server the module entitlement (via the Filament
 * resource `canViewAny`/`canCreate`, which combine the FeatureGate and RBAC),
 * the verified tenant context and the branch scope — UI visibility is never the
 * boundary. Orders are never deleted: they are cancelled (returning reserved
 * stock), fulfilled or refunded.
 */
final class CommerceController extends Controller
{
    // ========================================================= Catalog (products)

    public function products(Request $request): View
    {
        abort_unless(ProductResource::canViewAny(), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Product::STATUSES)],
        ]);
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';

        $products = Product::query()
            ->with('category')
            ->when($status !== '', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('sku', "%{$search}%"),
            ))
            ->orderBy('name')
            ->paginate(20)
            ->appends($filters);

        return view('clinic.tailadmin.products', [
            'products' => $products,
            'search' => $search,
            'status' => $status,
            'statusOptions' => $this->productStatusOptions(),
            'canCreate' => ProductResource::canCreate(),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function productCreate(): View
    {
        abort_unless(ProductResource::canCreate(), 403);

        return view('clinic.tailadmin.product-form', $this->productFormData(null));
    }

    public function productStore(SaveProductRequest $request, ProductCatalog $catalog): RedirectResponse
    {
        $catalog->create($request->validated(), $request->user());

        return to_route('clinic.tailadmin.products')->with('success', __('lba.commerce.products.created'));
    }

    public function productEdit(string $product): View
    {
        abort_unless(ProductResource::canViewAny(), 403);

        return view('clinic.tailadmin.product-form', $this->productFormData($this->resolveProduct($product)));
    }

    public function productUpdate(SaveProductRequest $request, string $product, ProductCatalog $catalog): RedirectResponse
    {
        $catalog->update($this->resolveProduct($product), $request->validated());

        return to_route('clinic.tailadmin.products')->with('success', __('lba.commerce.products.updated'));
    }

    public function productArchive(Request $request, string $product, ProductCatalog $catalog): RedirectResponse
    {
        abort_unless(ProductResource::canViewAny(), 403);

        $catalog->archive($this->resolveProduct($product));

        return to_route('clinic.tailadmin.products')->with('success', __('lba.commerce.products.archived'));
    }

    // ========================================================= Branch stock

    public function inventory(Request $request, ManagesBranchContext $branches): View
    {
        abort_unless(StockLevelResource::canViewAny(), 403);

        $branchOptions = $this->branchOptions($branches, $request->user());
        $filters = $request->validate([
            'branch' => ['nullable', Rule::in(array_keys($branchOptions))],
        ]);
        $branch = $filters['branch'] ?? '';

        $stock = InventoryStock::query()
            ->with(['product', 'branch'])
            ->whereIn('branch_id', $branch !== '' ? [$branch] : array_keys($branchOptions))
            ->orderBy('quantity')
            ->orderBy('id')
            ->paginate(20)
            ->appends($filters);

        return view('clinic.tailadmin.inventory', [
            'stock' => $stock,
            'branch' => $branch,
            'branchOptions' => $branchOptions,
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function inventoryReceive(Request $request, string $stock, InventoryService $inventory, ManagesBranchContext $branches): RedirectResponse
    {
        abort_unless(StockLevelResource::canViewAny(), 403);

        $row = $this->resolveStock($stock, $branches, $request->user());
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $inventory->receive($row->product, (string) $row->branch_id, (int) $data['quantity'], $request->user(), $data['reason'] ?? null);
        } catch (InventoryException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.inventory')->with('success', __('lba.commerce.inventory.received'));
    }

    public function inventoryAdjust(Request $request, string $stock, InventoryService $inventory, ManagesBranchContext $branches): RedirectResponse
    {
        abort_unless(StockLevelResource::canViewAny(), 403);

        $row = $this->resolveStock($stock, $branches, $request->user());
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $inventory->adjustTo($row->product, (string) $row->branch_id, (int) $data['quantity'], $request->user(), $data['reason'] ?? null);
        } catch (InventoryException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.inventory')->with('success', __('lba.commerce.inventory.adjusted'));
    }

    // ========================================================= Orders

    public function orders(Request $request, ManagesBranchContext $branches): View
    {
        abort_unless(OrderResource::canViewAny(), 403);

        $branchOptions = $this->branchOptions($branches, $request->user());
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'branch' => ['nullable', Rule::in(array_keys($branchOptions))],
        ]);
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';
        $branch = $filters['branch'] ?? '';
        $eligible = $branch !== '' ? [$branch] : array_keys($branchOptions);

        $orders = Order::query()
            ->with(['branch', 'patient'])
            ->whereIn('branch_id', $eligible)
            ->when($status !== '', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query
                    ->whereLike('order_number', "%{$search}%")
                    ->orWhereLike('customer_name', "%{$search}%"),
            ))
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->appends($filters);

        return view('clinic.tailadmin.orders', [
            'orders' => $orders,
            'search' => $search,
            'status' => $status,
            'branch' => $branch,
            'statusOptions' => $this->orderStatusOptions(),
            'branchOptions' => $branchOptions,
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function orderShow(Request $request, string $order, ManagesBranchContext $branches): View
    {
        abort_unless(OrderResource::canViewAny(), 403);

        $record = $this->resolveOrder($order, $branches, $request->user());
        $record->load(['items', 'invoices', 'transactions', 'branch', 'patient']);

        return view('clinic.tailadmin.order-show', [
            'order' => $record,
            'providerOptions' => $this->providerOptions((string) $record->currency),
            'canFulfil' => $record->isPaid() && $record->fulfillment_status === Order::FULFILLMENT_PENDING,
            'canCancel' => $record->isOpen() && (float) $record->paid_total <= 0,
            'canRefund' => ! $record->isRefunded() && ! $record->isCancelled() && (float) $record->paid_total > 0,
            'canPay' => $record->isOpen() && $record->outstanding() > 0,
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function orderFulfil(Request $request, string $order, OrderService $orders, ManagesBranchContext $branches): RedirectResponse
    {
        abort_unless(OrderResource::canViewAny(), 403);

        $record = $this->resolveOrder($order, $branches, $request->user());

        try {
            $orders->markFulfilled($record);
        } catch (OrderException $exception) {
            throw ValidationException::withMessages(['fulfil' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.orders.show', $record)->with('success', __('lba.commerce.orders.fulfilled'));
    }

    public function orderCancel(Request $request, string $order, OrderService $orders, ManagesBranchContext $branches): RedirectResponse
    {
        abort_unless(OrderResource::canViewAny(), 403);

        $record = $this->resolveOrder($order, $branches, $request->user());
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $orders->cancel($record, $request->user(), $data['reason'] ?? null);
        } catch (OrderException $exception) {
            throw ValidationException::withMessages(['cancel' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.orders.show', $record)->with('success', __('lba.commerce.orders.cancelled'));
    }

    public function orderRefund(Request $request, string $order, RefundService $refunds, ManagesBranchContext $branches): RedirectResponse
    {
        abort_unless(OrderResource::canViewAny(), 403);

        $record = $this->resolveOrder($order, $branches, $request->user());
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $refunds->refund($record, null, $request->user(), $data['reason'] ?? null);
        } catch (OrderException $exception) {
            throw ValidationException::withMessages(['refund' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.orders.show', $record)->with('success', __('lba.commerce.orders.refunded'));
    }

    public function orderPay(Request $request, string $order, PaymentManager $payments, ManagesBranchContext $branches): RedirectResponse
    {
        abort_unless(OrderResource::canViewAny(), 403);

        $record = $this->resolveOrder($order, $branches, $request->user());
        $providers = $this->providerOptions((string) $record->currency);
        $data = $request->validate(['provider' => ['required', Rule::in(array_keys($providers))]]);

        try {
            $initiation = $payments->charge($record, (string) $data['provider'], [
                'return_url' => route('clinic.tailadmin.orders.show', $record),
            ]);
        } catch (PaymentException|OrderException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.orders.show', $record)
            ->with('success', __('lba.commerce.orders.payment_started'))
            ->with('order_redirect_url', $initiation->redirectUrl);
    }

    // ========================================================= Helpers

    /**
     * @return array<string, mixed>
     */
    private function productFormData(?Product $product): array
    {
        return [
            'product' => $product,
            'categoryOptions' => $this->categoryOptions(),
            'kindOptions' => $this->kindOptions(),
            'statusOptions' => $this->productStatusOptions(),
            'currencyOptions' => $this->currencyOptions(),
            'links' => WorkspaceController::navigation(),
        ];
    }

    private function resolveProduct(string $product): Product
    {
        return Product::query()->whereKey($product)->firstOrFail();
    }

    private function resolveStock(string $stock, ManagesBranchContext $branches, ?User $user): InventoryStock
    {
        $row = InventoryStock::query()->whereKey($stock)->firstOrFail();
        abort_unless(in_array((string) $row->branch_id, array_keys($this->branchOptions($branches, $user)), true), 403);

        return $row;
    }

    private function resolveOrder(string $order, ManagesBranchContext $branches, ?User $user): Order
    {
        $record = Order::query()->whereKey($order)->firstOrFail();
        abort_unless(in_array((string) $record->branch_id, array_keys($this->branchOptions($branches, $user)), true), 403);

        return $record;
    }

    /**
     * Branch id => name for the branches the identity may operate in.
     *
     * @return array<string, string>
     */
    private function branchOptions(ManagesBranchContext $branches, ?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $options = [];

        foreach ($branches->eligibleOptions($user) as $option) {
            $options[(string) $option->id] = (string) $option->name;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function categoryOptions(): array
    {
        return ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    private function productStatusOptions(): array
    {
        $options = [];

        foreach (Product::STATUSES as $status) {
            $options[$status] = __("lba.commerce.products.status_{$status}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function kindOptions(): array
    {
        $options = [];

        foreach (Product::KINDS as $kind) {
            $options[$kind] = __("lba.commerce.products.kind_{$kind}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function currencyOptions(): array
    {
        $options = [];

        foreach (Currency::SUPPORTED as $currency) {
            $options[$currency] = $currency;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function orderStatusOptions(): array
    {
        $options = [];

        foreach (Order::STATUSES as $status) {
            $options[$status] = __("lba.commerce.orders.status_{$status}");
        }

        return $options;
    }

    /**
     * Sandbox gateways that could charge the given currency right now.
     *
     * @return array<string, string>
     */
    private function providerOptions(string $currency): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return app(PaymentManager::class)->availableFor($currency);
    }
}
