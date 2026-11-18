<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Filament\Pages\PosTerminal;
use Modules\Commerce\Filament\Resources\PaymentTransactionResource;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\PaymentSettlement;
use Modules\Commerce\Support\Currency;
use Modules\Patients\Models\Patient;

/**
 * TailAdmin point of sale and payment history for the Clinic panel.
 *
 * The Blade counterpart of {@see PosTerminal} and
 * {@see PaymentTransactionResource}: the cashier builds a cart from the active
 * catalog and checks out through the SAME domain services
 * ({@see OrderService} + {@see PaymentSettlement}), so no money path is
 * duplicated. Prices are always re-resolved server-side for the chosen
 * currency, the branch must be one the identity may operate in, and a cash
 * tender settles immediately while a provider tender only INITIATES a sandbox
 * charge (only a verified webhook may settle it).
 *
 * Every action re-derives the module entitlement, the acting permission, the
 * verified tenant context and the branch scope on the server — UI visibility is
 * never the boundary.
 */
final class PosController extends Controller
{
    public function index(Request $request, ManagesBranchContext $branches): View
    {
        abort_unless(PosTerminal::canAccess(), 403);

        $user = $request->user();

        return view('clinic.tailadmin.pos', [
            'products' => $this->products(),
            'branchOptions' => $branches->eligibleOptions($user),
            'currentBranchId' => $branches->currentBranchId($user),
            'patientOptions' => $this->patientOptions(),
            'currencyOptions' => $this->currencyOptions(),
            'methodOptions' => $this->methodOptions(),
            'providerOptions' => $this->providerOptions(),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    /**
     * Place the cart as an order and take payment.
     *
     * A stable idempotency key (carried by the rendered form) makes the whole
     * action replay-safe: a double-click returns the SAME order instead of
     * charging or stocking twice.
     */
    public function checkout(
        Request $request,
        ManagesBranchContext $branches,
        OrderService $orders,
        PaymentSettlement $settlement,
        PaymentManager $payments,
    ): RedirectResponse {
        abort_unless(PosTerminal::canAccess(), 403);

        $user = $request->user();
        $branchIds = array_map(
            static fn ($option): string => (string) $option->id,
            $branches->eligibleOptions($user),
        );

        $data = $request->validate([
            'branch_id' => ['required', 'ulid', Rule::in($branchIds)],
            'currency' => ['required', Rule::in(Currency::SUPPORTED)],
            'method' => ['required', Rule::in(PosTerminal::METHODS)],
            'provider' => ['nullable', 'string', 'max:60'],
            'patient_id' => ['nullable', 'string', 'max:64'],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $lines = [];

        foreach ($data['quantities'] as $productId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity > 0) {
                $lines[] = ['product_id' => (string) $productId, 'quantity' => $quantity];
            }
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['quantities' => __('lba.commerce.pos.cart_empty')]);
        }

        // A provider tender needs a REAL, currently-chargeable sandbox gateway:
        // the browser may only pick one the platform already offers for the
        // chosen currency.
        if ($data['method'] === PosTerminal::METHOD_PROVIDER
            && ! array_key_exists((string) ($data['provider'] ?? ''), $this->providerOptions($data['currency']))) {
            throw ValidationException::withMessages(['provider' => __('lba.commerce.pos.provider_none')]);
        }

        try {
            $order = $orders->place($lines, [
                'branch_id' => $data['branch_id'],
                'currency' => $data['currency'],
                'source' => Order::SOURCE_POS,
                'idempotency_key' => 'pos:'.$data['idempotency_key'],
                'patient_id' => $data['patient_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'discount_total' => (float) ($data['discount_total'] ?? 0),
                'tax_total' => (float) ($data['tax_total'] ?? 0),
                'notes' => $data['notes'] ?? null,
            ], $user);

            $redirectUrl = null;

            if ($data['method'] === PosTerminal::METHOD_CASH) {
                $settlement->record($order, [
                    'provider' => PosTerminal::METHOD_CASH,
                    'method' => PosTerminal::METHOD_CASH,
                    'currency' => $data['currency'],
                    'amount' => (float) $order->grand_total,
                ]);
            } elseif ($data['method'] === PosTerminal::METHOD_PROVIDER) {
                $initiation = $payments->charge($order, (string) $data['provider'], [
                    'return_url' => route('clinic.tailadmin.pos'),
                ]);
                $redirectUrl = $initiation->redirectUrl;
            }
        } catch (OrderException|PaymentException $exception) {
            throw ValidationException::withMessages(['checkout' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.pos')
            ->with('success', __('lba.commerce.pos.placed', ['number' => $order->order_number]))
            ->with('pos_redirect_url', $redirectUrl);
    }

    /**
     * Read-only payment history across the tenant, scoped to the branches the
     * identity may operate in. Transactions are append-only — never edited.
     */
    public function transactions(Request $request, ManagesBranchContext $branches): View
    {
        abort_unless(PaymentTransactionResource::canViewAny(), 403);

        $user = $request->user();
        $filters = $request->validate(['status' => ['nullable', Rule::in(PaymentTransaction::STATUSES)]]);
        $status = $filters['status'] ?? null;

        $branchIds = array_map(
            static fn ($option): string => (string) $option->id,
            $branches->eligibleOptions($user),
        );

        $transactions = PaymentTransaction::query()
            ->with('order')
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->when(
                $branchIds !== [],
                fn (Builder $query): Builder => $query->whereHas(
                    'order',
                    fn (Builder $inner): Builder => $inner->whereIn('branch_id', $branchIds),
                ),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)
            ->appends($filters);

        return view('clinic.tailadmin.transactions', [
            'transactions' => $transactions,
            'status' => $status,
            'statusOptions' => $this->statusOptions(),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    /**
     * @return Collection<int, Product>
     */
    private function products(): Collection
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return collect();
        }

        return Product::query()->active()->orderBy('name')->limit(60)->get();
    }

    /**
     * @return array<string, string>
     */
    private function patientOptions(): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return Patient::query()->active()->orderBy('full_name')->pluck('full_name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    private function currencyOptions(): array
    {
        $options = [];

        foreach (Currency::SUPPORTED as $code) {
            $options[$code] = $code;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function methodOptions(): array
    {
        $options = [];

        foreach (PosTerminal::METHODS as $method) {
            $options[$method] = __("lba.commerce.pos.method_{$method}");
        }

        return $options;
    }

    /**
     * Sandbox gateways that could charge the given currency right now. Sourced
     * from {@see PaymentManager::availableFor()}, so live/unconfigured gateways
     * are excluded unless the master switch is on and the gateway is verified.
     *
     * @return array<string, string>
     */
    private function providerOptions(string $currency = Currency::IDR): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return app(PaymentManager::class)->availableFor($currency);
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (PaymentTransaction::STATUSES as $status) {
            $options[$status] = __("lba.commerce.orders.payment_status_{$status}");
        }

        return $options;
    }
}
