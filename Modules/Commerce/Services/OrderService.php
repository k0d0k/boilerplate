<?php

namespace Modules\Commerce\Services;

use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Invoice;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\OrderItem;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Models\StockMovement;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — order lifecycle.
 *
 * The only place an order, its lines and its invoice are written. Everything
 * runs inside a transaction on the VERIFIED tenant database, so a partial order
 * can never survive a failure.
 *
 * Stock policy: an order RESERVES stock at placement by issuing it through
 * {@see InventoryService} in the same transaction. Because the issue path
 * refuses to drive a branch below zero, an order can never be placed against
 * unavailable stock — and a cancelled/refunded order returns it.
 */
final class OrderService
{
    use RunsVerifiedTenantTransactions;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Place an order from a set of requested lines.
     *
     * @param  list<array{product_id: string, quantity: int}>  $lines
     * @param  array{
     *     branch_id: string,
     *     currency: string,
     *     source?: string,
     *     patient_id?: string|null,
     *     idempotency_key?: string|null,
     *     customer_name?: string|null,
     *     customer_email?: string|null,
     *     customer_phone?: string|null,
     *     discount_total?: float|int|string,
     *     tax_total?: float|int|string,
     *     notes?: string|null,
     *     metadata?: array<string, mixed>|null,
     * }  $context
     */
    public function place(array $lines, array $context, ?User $actor = null): Order
    {
        return $this->tenantTransaction(function () use ($lines, $context, $actor): Order {
            if ($lines === []) {
                throw OrderException::emptyCart();
            }

            // Durable idempotency: a double-clicked checkout (or a retried
            // request) with the same key returns the ORIGINAL order instead of
            // creating a second one and reserving stock twice. The unique index
            // on `idempotency_key` is the last line of defence under a race.
            $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));

            if ($idempotencyKey !== '') {
                $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $currency = Currency::assertSupported((string) ($context['currency'] ?? Currency::IDR));
            $branchId = (string) ($context['branch_id'] ?? '');

            if ($branchId === '' || ! TenantBranch::query()->whereKey($branchId)->exists()) {
                throw OrderException::unknownBranch($branchId);
            }

            $source = (string) ($context['source'] ?? Order::SOURCE_POS);

            if (! in_array($source, Order::SOURCES, true)) {
                $source = Order::SOURCE_POS;
            }

            $order = new Order;
            $order->fill([
                'source' => $source,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'branch_id' => $branchId,
                'patient_id' => $context['patient_id'] ?? null,
                'merchant_id' => app(MerchantRegistry::class)->forCurrency($currency)?->getKey(),
                'customer_name' => $context['customer_name'] ?? null,
                'customer_email' => $context['customer_email'] ?? null,
                'customer_phone' => $context['customer_phone'] ?? null,
                'currency' => $currency,
                'discount_total' => $this->money($currency, $context['discount_total'] ?? 0),
                'tax_total' => $this->money($currency, $context['tax_total'] ?? 0),
                'status' => Order::STATUS_PENDING,
                'fulfillment_status' => Order::FULFILLMENT_PENDING,
                'placed_by' => $actor?->getKey(),
                'placed_at' => now(),
                'notes' => $context['notes'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ]);
            $order->order_number = $this->nextNumber('ORD', 'orders', 'order_number');
            $order->save();

            $subtotal = 0.0;

            foreach ($lines as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($quantity < 1) {
                    throw OrderException::invalidQuantity($quantity);
                }

                $product = Product::query()->whereKey((string) ($line['product_id'] ?? ''))->first();

                if ($product === null || ! $product->isActive()) {
                    throw OrderException::productUnavailable((string) ($line['product_id'] ?? ''));
                }

                $unitPrice = $product->priceFor($currency);

                if ($unitPrice === null) {
                    throw OrderException::unpricedProduct((string) $product->getKey(), $currency);
                }

                $lineTotal = (float) Currency::round($unitPrice * $quantity, $currency);
                $subtotal += $lineTotal;

                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    'product_id' => $product->getKey(),
                    'name' => (string) $product->name,
                    'sku' => $product->sku,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'currency' => $currency,
                ]);

                if ($product->track_inventory) {
                    $this->inventory->issue(
                        $product,
                        $branchId,
                        $quantity,
                        $actor,
                        'Order '.$order->order_number,
                        Order::class,
                        (string) $order->getKey(),
                        StockMovement::TYPE_SALE,
                    );
                }
            }

            $subtotal = (float) Currency::round($subtotal, $currency);
            $discount = $this->money($currency, $context['discount_total'] ?? 0);
            $tax = $this->money($currency, $context['tax_total'] ?? 0);
            $grand = (float) Currency::round($subtotal - $discount + $tax, $currency);

            if ($grand < 0) {
                $grand = 0.0;
            }

            $order->forceFill([
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'tax_total' => $tax,
                'grand_total' => $grand,
            ])->save();

            Invoice::query()->create([
                'order_id' => $order->getKey(),
                'number' => $this->nextNumber('INV', 'invoices', 'number'),
                'currency' => $currency,
                'amount' => $grand,
                'status' => Invoice::STATUS_ISSUED,
                'issued_at' => now(),
            ]);

            return $order->refresh();
        });
    }

    /**
     * Mark an order fulfilled (goods handed over). Only a settled order may be
     * fulfilled.
     */
    public function markFulfilled(Order $order): Order
    {
        return $this->tenantTransaction(function () use ($order): Order {
            if (! $order->isPaid()) {
                throw OrderException::notOpen((string) $order->order_number);
            }

            $order->forceFill(['fulfillment_status' => Order::FULFILLMENT_FULFILLED])->save();

            return $order;
        });
    }

    /**
     * Cancel an order and RETURN any reserved stock to the branch. A settled
     * order cannot be cancelled — it must be refunded instead.
     */
    public function cancel(Order $order, ?User $actor = null, ?string $reason = null): Order
    {
        return $this->tenantTransaction(function () use ($order, $actor, $reason): Order {
            if ($order->isCancelled()) {
                return $order;
            }

            if ((float) $order->paid_total > 0) {
                throw OrderException::notOpen((string) $order->order_number);
            }

            $this->restock($order, $actor, $reason ?? 'Order cancelled');

            $order->forceFill([
                'status' => Order::STATUS_CANCELLED,
                'fulfillment_status' => Order::FULFILLMENT_CANCELLED,
            ])->save();

            $order->invoices()->update([
                'status' => Invoice::STATUS_VOID,
            ]);

            return $order->refresh();
        });
    }

    /**
     * Return the order's still-reserved stock to its branch. Stock is only
     * returned once per order (guarded by the caller's status transitions).
     */
    public function restock(Order $order, ?User $actor = null, ?string $reason = null): void
    {
        foreach ($order->items()->with('product')->get() as $item) {
            $product = $item->product;

            if ($product === null || ! $product->track_inventory) {
                continue;
            }

            $this->inventory->receive(
                $product,
                (string) $order->branch_id,
                (int) $item->quantity,
                $actor,
                $reason,
                Order::class,
                (string) $order->getKey(),
                StockMovement::TYPE_REFUND,
            );
        }
    }

    private function money(string $currency, float|int|string $amount): float
    {
        return (float) Currency::round((float) $amount, $currency);
    }

    /**
     * Generate the next human-readable, tenant-unique document number
     * (`ORD-2026-000001`). Collisions are retried because the unique index is
     * the source of truth.
     */
    private function nextNumber(string $prefix, string $table, string $column): string
    {
        $year = now()->format('Y');
        $like = $prefix.'-'.$year.'-%';

        $sequence = (int) Order::query()->getConnection()
            ->table($table)
            ->where($column, 'like', $like)
            ->count();

        do {
            $sequence++;
            $number = sprintf('%s-%s-%06d', $prefix, $year, $sequence);
        } while (Order::query()->getConnection()->table($table)->where($column, $number)->exists());

        return $number;
    }
}
