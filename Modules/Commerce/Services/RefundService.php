<?php

namespace Modules\Commerce\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Invoice;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Support\Currency;

/**
 * WS3 / WS8.C — refund settlement (cash/manual and remote).
 *
 * The ONLY writer of an order refund. A refund is a distinct, auditable money
 * movement recorded on the append-only ledger, so a settled payment is never
 * overwritten — history is preserved.
 *
 * Two clearly separated paths:
 *  - {@see self::refund()} is the CASH / MANUAL path: an operator refunds in
 *    person and the reversal completes immediately (with the actor and reason
 *    recorded). It must never be used to fake a card-provider refund.
 *  - {@see self::requestProviderRefund()} records an ASYNC provider refund
 *    REQUEST as a `pending` row and changes nothing else; the order is only
 *    completed by {@see self::settleProviderRefund()} when the provider
 *    CONFIRMS (a verified webhook). A provider refund is never marked complete
 *    locally before the provider says so.
 *
 * Refund rules:
 *  - only an order with a SETTLED balance can be refunded;
 *  - the refund can never exceed the settled amount (over-refund refused);
 *  - a single completed refund per order is enforced by a UNIQUE idempotency
 *    key, so a retried request can never refund twice;
 *  - the order's still-reserved stock is returned to its branch, the order is
 *    marked `refunded` / `returned`, and its invoices are voided.
 *
 * All of this runs on the VERIFIED tenant database.
 */
final class RefundService
{
    use RunsVerifiedTenantTransactions;

    public function __construct(private readonly OrderService $orders) {}

    /**
     * CASH / MANUAL refund: refund a settled order in person (in full by
     * default), return its stock and complete the reversal immediately.
     *
     * @param  float|int|string|null  $amount  null refunds the full settled amount.
     * @param  array<string, mixed>|null  $evidence  auditable payout evidence (reference, note).
     */
    public function refund(Order $order, float|int|string|null $amount = null, ?User $actor = null, ?string $reason = null, ?array $evidence = null): Order
    {
        return $this->tenantTransaction(function () use ($order, $amount, $actor, $reason, $evidence): Order {
            if ($order->isRefunded()) {
                return $order;
            }

            if ($order->isCancelled()) {
                throw OrderException::notOpen((string) $order->order_number);
            }

            $currency = Currency::assertSupported((string) $order->currency);
            $settled = (float) Currency::round((float) $order->paid_total, $currency);

            if ($settled <= 0) {
                throw OrderException::notRefundable((string) $order->order_number);
            }

            $refund = $amount === null
                ? $settled
                : (float) Currency::round((float) $amount, $currency);

            if ($refund <= 0 || $refund > $settled + 0.0001) {
                throw OrderException::overRefund((string) $order->order_number);
            }

            // Idempotent: one completed refund per order.
            $key = 'refund:'.$order->getKey();

            if (PaymentTransaction::query()->where('idempotency_key', $key)->exists()) {
                return $order->refresh();
            }

            // Return the order's still-reserved stock to its branch.
            $this->orders->restock($order, $actor, $reason ?? 'Order refunded');

            PaymentTransaction::query()->create([
                'order_id' => $order->getKey(),
                'invoice_id' => $order->invoices()->latest('created_at')->value('id'),
                'provider' => 'refund',
                'provider_reference' => $key,
                'method' => 'refund',
                'currency' => $currency,
                'amount' => $refund,
                'status' => PaymentTransaction::STATUS_REFUNDED,
                'idempotency_key' => $key,
                'paid_at' => now(),
                'metadata' => [
                    'reason' => $reason,
                    'refunded_by' => $actor?->getKey(),
                    'refunded_total' => $refund,
                    'refund_kind' => 'cash',
                    'evidence' => $evidence,
                ],
            ]);

            $order->forceFill([
                'status' => Order::STATUS_REFUNDED,
                'fulfillment_status' => Order::FULFILLMENT_RETURNED,
            ])->save();

            $order->invoices()->update(['status' => Invoice::STATUS_VOID]);

            return $order->refresh();
        });
    }

    /**
     * REMOTE refund request: record the intent to refund through a provider.
     *
     * Nothing is completed here — no stock is returned and the order is NOT
     * marked refunded. The ledger row stays `pending` until the provider
     * confirms via {@see self::settleProviderRefund()}.
     *
     * @param  float|int|string|null  $amount  null refunds the full settled amount.
     */
    public function requestProviderRefund(Order $order, string $provider, float|int|string|null $amount = null, ?User $actor = null, ?string $reason = null): PaymentTransaction
    {
        return $this->tenantTransaction(function () use ($order, $provider, $amount, $actor, $reason): PaymentTransaction {
            if ($order->isRefunded()) {
                throw OrderException::alreadyRefunded((string) $order->order_number);
            }

            $currency = Currency::assertSupported((string) $order->currency);
            $settled = (float) Currency::round((float) $order->paid_total, $currency);

            if ($settled <= 0) {
                throw OrderException::notRefundable((string) $order->order_number);
            }

            $refund = $amount === null
                ? $settled
                : (float) Currency::round((float) $amount, $currency);

            if ($refund <= 0 || $refund > $settled + 0.0001) {
                throw OrderException::overRefund((string) $order->order_number);
            }

            $key = 'refund-request:'.$order->getKey().':'.$provider;

            $existing = PaymentTransaction::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }

            return PaymentTransaction::query()->create([
                'order_id' => $order->getKey(),
                'invoice_id' => $order->invoices()->latest('created_at')->value('id'),
                'provider' => $provider,
                'provider_reference' => (string) $order->order_number,
                'method' => 'refund',
                'currency' => $currency,
                'amount' => $refund,
                'status' => PaymentTransaction::STATUS_PENDING,
                'idempotency_key' => $key,
                'metadata' => [
                    'reason' => $reason,
                    'requested_by' => $actor?->getKey(),
                    'refund_kind' => 'provider',
                ],
            ]);
        });
    }

    /**
     * Complete a remote refund after the provider CONFIRMS it.
     *
     * Promotes the still-pending request row in place (never a duplicate),
     * returns the reserved stock once, marks the order refunded and voids its
     * invoices. Idempotent: a replayed confirmation is a no-op.
     *
     * @param  array<string, mixed>  $raw
     */
    public function settleProviderRefund(Order $order, string $provider, float|int|string|null $amount = null, ?string $reason = null, array $raw = []): Order
    {
        return $this->tenantTransaction(function () use ($order, $provider, $amount, $reason, $raw): Order {
            if ($order->isRefunded()) {
                return $order;
            }

            if ($order->isCancelled()) {
                throw OrderException::notOpen((string) $order->order_number);
            }

            $currency = Currency::assertSupported((string) $order->currency);
            $settled = (float) Currency::round((float) $order->paid_total, $currency);

            if ($settled <= 0) {
                throw OrderException::notRefundable((string) $order->order_number);
            }

            $refund = $amount === null
                ? $settled
                : (float) Currency::round((float) $amount, $currency);

            if ($refund <= 0 || $refund > $settled + 0.0001) {
                throw OrderException::overRefund((string) $order->order_number);
            }

            $completedKey = 'refund:'.$order->getKey();
            $requestKey = 'refund-request:'.$order->getKey().':'.$provider;

            $request = PaymentTransaction::query()
                ->whereIn('idempotency_key', [$requestKey, $completedKey])
                ->latest('created_at')
                ->first();

            if ($request !== null && $request->status === PaymentTransaction::STATUS_REFUNDED) {
                return $order;
            }

            // Return the order's still-reserved stock exactly once.
            $this->orders->restock($order, null, $reason ?? 'Provider refund confirmed');

            if ($request !== null) {
                $request->forceFill([
                    'status' => PaymentTransaction::STATUS_REFUNDED,
                    'provider' => $provider,
                    'provider_reference' => $request->provider_reference ?: (string) $order->order_number,
                    'idempotency_key' => $completedKey,
                    'amount' => $refund,
                    'currency' => $currency,
                    'paid_at' => now(),
                    'response_payload' => $raw,
                ])->save();
            } else {
                // The provider confirmed a refund we never requested: still
                // complete it (money moved back) but record it distinctly.
                PaymentTransaction::query()->create([
                    'order_id' => $order->getKey(),
                    'invoice_id' => $order->invoices()->latest('created_at')->value('id'),
                    'provider' => $provider,
                    'provider_reference' => (string) $order->order_number,
                    'method' => 'refund',
                    'currency' => $currency,
                    'amount' => $refund,
                    'status' => PaymentTransaction::STATUS_REFUNDED,
                    'idempotency_key' => $completedKey,
                    'paid_at' => now(),
                    'response_payload' => $raw,
                    'metadata' => [
                        'reason' => $reason,
                        'refund_kind' => 'provider',
                        'unsolicited' => true,
                    ],
                ]);
            }

            $order->forceFill([
                'status' => Order::STATUS_REFUNDED,
                'fulfillment_status' => Order::FULFILLMENT_RETURNED,
            ])->save();

            $order->invoices()->update(['status' => Invoice::STATUS_VOID]);

            return $order->refresh();
        });
    }
}
