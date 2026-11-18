<?php

namespace Modules\Commerce\Services;

use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Invoice;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — the single writer of payment transactions.
 *
 * Every payment path records through here: a POS cash tender and a manual
 * transfer via {@see record()}, and a verified gateway webhook via
 * {@see settle()}. The duplicate-payment guarantees hold on every path:
 *
 *  - a supplied `idempotency_key` (or a `[provider, provider_reference]` pair)
 *    resolves to an EXISTING transaction instead of creating a second one;
 *  - a webhook replay of the same status is a no-op, and a late `pending`
 *    transaction is promoted IN PLACE to its terminal status — never duplicated;
 *  - a settled payment that would exceed the order's outstanding balance is
 *    rejected, so an order can never be paid twice;
 *  - the payment currency must match the order currency exactly.
 *
 * All of this runs on the VERIFIED tenant database.
 */
final class PaymentSettlement
{
    use RunsVerifiedTenantTransactions;

    /**
     * Record a payment attempt against an order (initiation, manual tender or
     * POS cash). A successful amount advances the order immediately.
     *
     * @param  array{
     *     provider: string,
     *     amount: float|int|string,
     *     provider_reference?: string|null,
     *     method?: string|null,
     *     currency?: string|null,
     *     status?: string,
     *     idempotency_key?: string|null,
     *     request_payload?: array<string, mixed>|null,
     *     response_payload?: array<string, mixed>|null,
     *     metadata?: array<string, mixed>|null,
     * }  $attributes
     */
    public function record(Order $order, array $attributes): PaymentTransaction
    {
        return $this->tenantTransaction(function () use ($order, $attributes): PaymentTransaction {
            $provider = (string) $attributes['provider'];
            $reference = $attributes['provider_reference'] ?? null;
            $status = $this->normaliseStatus((string) ($attributes['status'] ?? PaymentTransaction::STATUS_SUCCEEDED));

            $idempotencyKey = (string) ($attributes['idempotency_key'] ?? $this->keyFor($order, $provider, $reference));

            $existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()
                ?? ($reference !== null && $reference !== ''
                    ? PaymentTransaction::query()->where('provider', $provider)->where('provider_reference', $reference)->first()
                    : null);

            if ($existing !== null) {
                return $existing;
            }

            $currency = Currency::assertSupported((string) ($attributes['currency'] ?? $order->currency));
            $this->assertCurrency($order, $currency);

            $amount = (float) Currency::round((float) $attributes['amount'], $currency);
            $this->assertAmount($amount);

            if ($status === PaymentTransaction::STATUS_SUCCEEDED) {
                $this->guardAgainstDuplicatePayment($order, $amount);
            }

            $transaction = $this->insert($order, [
                'provider' => $provider,
                'provider_reference' => $reference,
                'method' => $attributes['method'] ?? null,
                'currency' => $currency,
                'amount' => $amount,
                'status' => $status,
                'idempotency_key' => $idempotencyKey,
                'request_payload' => $attributes['request_payload'] ?? null,
                'response_payload' => $attributes['response_payload'] ?? null,
                'paid_at' => $status === PaymentTransaction::STATUS_SUCCEEDED ? now() : null,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            if ($status === PaymentTransaction::STATUS_SUCCEEDED) {
                $this->applySettlement($order, $amount);
            }

            return $transaction;
        });
    }

    /**
     * Apply a verified gateway webhook idempotently.
     *
     * A webhook for a transaction we initiated promotes it IN PLACE; a webhook
     * for a reference we never saw creates one (so an out-of-band settlement is
     * still recorded). A replay of the same status is a no-op.
     *
     * @param  array<string, mixed>  $raw
     */
    public function settle(
        Order $order,
        string $provider,
        ?string $reference,
        string $status,
        float $amount,
        string $currency,
        array $raw = [],
    ): PaymentTransaction {
        return $this->tenantTransaction(function () use ($order, $provider, $reference, $status, $amount, $currency, $raw): PaymentTransaction {
            $status = $this->normaliseStatus($status);
            $currency = Currency::assertSupported($currency);
            $this->assertCurrency($order, $currency);
            $amount = (float) Currency::round($amount, $currency);

            $existing = $this->find($order, $provider, $reference);

            if ($existing === null) {
                $this->assertAmount($amount);

                if ($status === PaymentTransaction::STATUS_SUCCEEDED) {
                    $this->guardAgainstDuplicatePayment($order, $amount);
                }

                $transaction = $this->insert($order, [
                    'provider' => $provider,
                    'provider_reference' => $reference,
                    'currency' => $currency,
                    'amount' => $amount,
                    'status' => $status,
                    'idempotency_key' => $this->keyFor($order, $provider, $reference),
                    'response_payload' => $raw,
                    'paid_at' => $status === PaymentTransaction::STATUS_SUCCEEDED ? now() : null,
                ]);

                if ($status === PaymentTransaction::STATUS_SUCCEEDED) {
                    $this->applySettlement($order, $amount);
                }

                return $transaction;
            }

            // Replay of the same status: nothing to do.
            if ($existing->status === $status) {
                return $existing;
            }

            // Promote a still-open transaction to its terminal status, once.
            if (! $this->isTerminal($existing->status) && $this->isTerminal($status)) {
                if ($status === PaymentTransaction::STATUS_SUCCEEDED) {
                    $this->guardAgainstDuplicatePayment($order, (float) $existing->amount);
                }

                $existing->forceFill([
                    'status' => $status,
                    'response_payload' => $raw,
                    'paid_at' => $status === PaymentTransaction::STATUS_SUCCEEDED ? now() : null,
                ])->save();

                if ($status === PaymentTransaction::STATUS_SUCCEEDED) {
                    $this->applySettlement($order, (float) $existing->amount);
                }

                return $existing;
            }

            // A contradictory or out-of-order event: keep the recorded history.
            return $existing;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(Order $order, array $attributes): PaymentTransaction
    {
        return PaymentTransaction::query()->create($attributes + [
            'order_id' => $order->getKey(),
            'invoice_id' => $order->invoices()->latest('created_at')->value('id'),
            'method' => null,
            'request_payload' => null,
            'response_payload' => null,
            'metadata' => null,
        ]);
    }

    private function find(Order $order, string $provider, ?string $reference): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('provider', $provider)
            ->when(
                filled($reference),
                fn ($query) => $query->where('provider_reference', $reference),
                fn ($query) => $query->where('order_id', $order->getKey()),
            )
            ->latest('created_at')
            ->first();
    }

    /**
     * Advance the order's paid total and status after a successful settlement.
     */
    private function applySettlement(Order $order, float $amount): void
    {
        $currency = (string) $order->currency;
        $paid = (float) Currency::round((float) $order->paid_total + $amount, $currency);
        $grand = (float) $order->grand_total;

        $order->forceFill([
            'paid_total' => $paid,
            'status' => $paid + 0.0001 >= $grand ? Order::STATUS_PAID : Order::STATUS_PARTIALLY_PAID,
        ])->save();

        if ($order->isPaid()) {
            $order->invoices()
                ->where('status', Invoice::STATUS_ISSUED)
                ->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);
        }
    }

    private function guardAgainstDuplicatePayment(Order $order, float $amount): void
    {
        if (! $order->isOpen()) {
            throw OrderException::alreadySettled((string) $order->order_number);
        }

        if ($amount > $order->outstanding() + 0.0001) {
            throw OrderException::overPayment((string) $order->order_number);
        }
    }

    private function assertCurrency(Order $order, string $currency): void
    {
        if ($currency !== strtoupper((string) $order->currency)) {
            throw OrderException::currencyMismatch(strtoupper((string) $order->currency), $currency);
        }
    }

    private function assertAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw OrderException::invalidQuantity((int) $amount);
        }
    }

    private function normaliseStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, PaymentTransaction::STATUSES, true)
            ? $status
            : PaymentTransaction::STATUS_PENDING;
    }

    private function isTerminal(string $status): bool
    {
        return in_array($status, [
            PaymentTransaction::STATUS_SUCCEEDED,
            PaymentTransaction::STATUS_FAILED,
            PaymentTransaction::STATUS_EXPIRED,
            PaymentTransaction::STATUS_REFUNDED,
        ], true);
    }

    private function keyFor(Order $order, string $provider, ?string $reference): string
    {
        if (filled($reference)) {
            return $provider.':'.$reference;
        }

        // No external reference: a manual tender is a distinct event, so a
        // fresh key is generated (it is never a retry of an earlier one).
        return $provider.':'.(string) $order->getKey().':'.Str::ulid();
    }
}
