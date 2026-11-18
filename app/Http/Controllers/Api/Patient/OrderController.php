<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Access\PermissionKey;
use App\Platform\Api\Support\ApiResponse;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — patient purchase history and checkout.
 *
 * Every order is self-scoped: the acting identity's own patient id is the only
 * `patient_id` ever written or read, so a foreign order id resolves to nothing
 * (404) and existence is never leaked. Checkout delegates to the SAME
 * {@see OrderService} the clinic uses, so stock reservation, currency and
 * pricing rules apply unchanged. Requires `order.own`.
 */
final class OrderController extends PatientApiController
{
    use PresentsPatientApi;

    public function index(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::ORDER_OWN);
        $patient = $this->auth->patient();

        $query = Order::query()
            ->with('branch')
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('placed_at');

        if ($request->filled('status')) {
            $status = (string) $request->query('status');

            if (! in_array($status, Order::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Unknown order status.']);
            }

            $query->where('status', $status);
        }

        $paginator = $query
            ->paginate($this->perPage($request))
            ->through(fn (Order $order) => $this->orderArray($order));

        return ApiResponse::paginated($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::ORDER_OWN);
        $patient = $this->auth->patient();

        $validated = $request->validate([
            'branch_id' => ['required', 'string'],
            'currency' => ['nullable', 'string', 'in:'.implode(',', Currency::SUPPORTED)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // The branch must be an ACTIVE branch of the bound clinic database.
        if (! TenantBranch::query()->active()->whereKey($validated['branch_id'])->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'The selected branch is not available.']);
        }

        $currency = strtoupper((string) ($validated['currency'] ?? Currency::IDR));
        Currency::assertSupported($currency);

        $lines = array_map(
            fn (array $item) => [
                'product_id' => (string) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ],
            $validated['items'],
        );

        try {
            $order = app(OrderService::class)->place($lines, [
                'branch_id' => (string) $validated['branch_id'],
                'currency' => $currency,
                'source' => Order::SOURCE_API,
                'patient_id' => $patient->getKey(),
                'customer_name' => $patient->full_name,
                'customer_email' => $patient->email,
                'customer_phone' => $patient->phone,
                'notes' => $validated['notes'] ?? null,
            ], $this->auth->user());
        } catch (OrderException $exception) {
            return ApiResponse::error('order_rejected', $exception->getMessage(), 422);
        }

        return ApiResponse::ok($this->orderArray($order->load('branch', 'items', 'invoices', 'transactions'), true), [], 201);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $this->auth->authorize(PermissionKey::ORDER_OWN);
        $patient = $this->auth->patient();

        $model = $this->ownOrder($order, (string) $patient->getKey());

        return ApiResponse::ok($this->orderArray($model, true));
    }

    /**
     * WS8.B — start a sandbox provider payment for the patient's OWN order.
     *
     * Self-scoped exactly like {@see self::show}: a foreign order id resolves to
     * 404, never leaking existence. The order is NOT marked paid here — only a
     * verified provider webhook settles it — so the response carries the hosted
     * redirect URL the app opens in a browser.
     */
    public function pay(Request $request, string $order): JsonResponse
    {
        $this->auth->authorize(PermissionKey::ORDER_OWN);
        $patient = $this->auth->patient();

        $model = $this->ownOrder($order, (string) $patient->getKey());

        $validated = $request->validate([
            'provider' => ['required', 'string'],
        ]);

        $provider = (string) $validated['provider'];
        $currency = strtoupper((string) $model->currency);

        // The provider must be one the platform can actually charge right now
        // for this order's currency — a client can never widen the set.
        if (! app(PaymentManager::class)->isAvailable($provider, $currency)) {
            return ApiResponse::error('payment_unavailable', 'That payment method is not available for this order.', 422);
        }

        if (! $model->isOpen()) {
            return ApiResponse::error('order_not_open', 'This order can no longer be paid.', 422);
        }

        try {
            $initiation = app(PaymentManager::class)->charge($model, $provider, [
                'return_url' => url('/api/v1/patient/orders/'.$model->getKey()),
            ]);
        } catch (OrderException|PaymentException $exception) {
            return ApiResponse::error('payment_rejected', $exception->getMessage(), 422);
        }

        return ApiResponse::ok([
            'order_id' => $model->getKey(),
            'order_number' => $model->order_number,
            'provider' => $provider,
            'status' => $initiation->intent->status,
            'reference' => $initiation->intent->provider_reference,
            'redirect_url' => $initiation->redirectUrl,
        ]);
    }

    /**
     * Load an order that belongs to the acting patient, or 404. A foreign id
     * simply resolves to nothing, so existence is never leaked.
     */
    private function ownOrder(string $orderId, string $patientId): Order
    {
        $order = Order::query()
            ->with('branch', 'items', 'invoices', 'transactions')
            ->whereKey($orderId)
            ->where('patient_id', $patientId)
            ->first();

        abort_if($order === null, 404);

        return $order;
    }
}
