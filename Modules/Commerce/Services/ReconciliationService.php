<?php

namespace Modules\Commerce\Services;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Database\Eloquent\Builder;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — commerce reconciliation (read-only).
 *
 * Compares what the orders SAY was settled (`orders.paid_total`) with what the
 * append-only payment ledger RECORDS (the sum of `succeeded` transactions), per
 * currency, and reports any drift. It never writes: a discrepancy is surfaced
 * for an operator to investigate, never silently "corrected".
 *
 * Money is never summed across currencies — each currency is reconciled on its
 * own line.
 */
final class ReconciliationService
{
    public function __construct(private readonly ResolvesTenantDatabase $databases) {}

    /**
     * Per-currency ledger totals.
     *
     * When `$branchIds` is given the report is restricted to orders placed in
     * those branches (the branches the acting identity may operate in); the raw
     * ledger is restricted to those orders too, so a branch manager never sees
     * another branch's money. `null` means "no branch restriction".
     *
     * @param  list<string>|null  $branchIds
     * @return array<string, array{collected: float, refunded: float, outstanding: float, orders: int, settled_orders: int}>
     */
    public function summary(?array $branchIds = null): array
    {
        $summary = [];

        foreach (Currency::SUPPORTED as $currency) {
            $summary[$currency] = [
                'collected' => 0.0,
                'refunded' => 0.0,
                'outstanding' => 0.0,
                'orders' => 0,
                'settled_orders' => 0,
            ];
        }

        if ($this->databases->current() === null) {
            return $summary;
        }

        $orders = $this->orders($branchIds)->get();

        foreach ($orders as $order) {
            $currency = strtoupper((string) $order->currency);

            if (! isset($summary[$currency])) {
                continue;
            }

            $summary[$currency]['orders']++;

            if ($order->isPaid()) {
                $summary[$currency]['settled_orders']++;
            }

            if ($order->isOpen() || $order->isPaid()) {
                $summary[$currency]['outstanding'] += max($order->outstanding(), 0.0);
            }
        }

        $orderIds = $orders->modelKeys();

        $collected = PaymentTransaction::query()
            ->where('status', PaymentTransaction::STATUS_SUCCEEDED)
            ->whereIn('order_id', $orderIds)
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $refunded = PaymentTransaction::query()
            ->where('status', PaymentTransaction::STATUS_REFUNDED)
            ->whereIn('order_id', $orderIds)
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        foreach (Currency::SUPPORTED as $currency) {
            $summary[$currency]['collected'] = (float) Currency::round((float) ($collected[$currency] ?? 0), $currency);
            $summary[$currency]['refunded'] = (float) Currency::round((float) ($refunded[$currency] ?? 0), $currency);
            $summary[$currency]['outstanding'] = (float) Currency::round($summary[$currency]['outstanding'], $currency);
        }

        return $summary;
    }

    /**
     * Per-CURRENCY, per-MERCHANT ledger totals.
     *
     * Funds must never be pooled across unrelated partners, so this reconciles
     * collections and refunds on BOTH dimensions at once — an IDR total for one
     * merchant is never merged with another merchant's, nor with another
     * currency. Orders without a merchant of record are grouped under
     * `unassigned` so nothing is silently hidden.
     *
     * @param  list<string>|null  $branchIds
     * @return list<array{merchant_id: string|null, merchant: string, currency: string, collected: float, refunded: float, orders: int}>
     */
    public function summaryByMerchant(?array $branchIds = null): array
    {
        if ($this->databases->current() === null) {
            return [];
        }

        $orders = $this->orders($branchIds)->with('merchant')->get();
        $orderIds = $orders->modelKeys();

        $collectedByOrder = PaymentTransaction::query()
            ->where('status', PaymentTransaction::STATUS_SUCCEEDED)
            ->whereIn('order_id', $orderIds)
            ->selectRaw('order_id, SUM(amount) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        $refundedByOrder = PaymentTransaction::query()
            ->where('status', PaymentTransaction::STATUS_REFUNDED)
            ->whereIn('order_id', $orderIds)
            ->selectRaw('order_id, SUM(amount) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        /** @var array<string, array{merchant_id: string|null, merchant: string, currency: string, collected: float, refunded: float, orders: int}> $rows */
        $rows = [];

        foreach ($orders as $order) {
            $currency = strtoupper((string) $order->currency);

            if (! in_array($currency, Currency::SUPPORTED, true)) {
                continue;
            }

            $merchantId = $order->merchant_id !== null ? (string) $order->merchant_id : null;
            $key = ($merchantId ?? 'unassigned').'|'.$currency;

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'merchant_id' => $merchantId,
                    'merchant' => $order->merchant?->name !== null ? (string) $order->merchant->name : 'Unassigned',
                    'currency' => $currency,
                    'collected' => 0.0,
                    'refunded' => 0.0,
                    'orders' => 0,
                ];
            }

            $rows[$key]['orders']++;
            $rows[$key]['collected'] += (float) ($collectedByOrder[$order->getKey()] ?? 0);
            $rows[$key]['refunded'] += (float) ($refundedByOrder[$order->getKey()] ?? 0);
        }

        $result = [];

        foreach ($rows as $row) {
            $row['collected'] = (float) Currency::round($row['collected'], $row['currency']);
            $row['refunded'] = (float) Currency::round($row['refunded'], $row['currency']);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Orders whose recorded settled total does not match the ledger.
     *
     * @param  list<string>|null  $branchIds
     * @return list<array{order_number: string, currency: string, recorded: string, expected: string, difference: string}>
     */
    public function discrepancies(int $limit = 50, ?array $branchIds = null): array
    {
        if ($this->databases->current() === null) {
            return [];
        }

        $orders = $this->orders($branchIds)
            ->withSum(
                ['transactions as settled_sum' => fn ($query) => $query->where('status', PaymentTransaction::STATUS_SUCCEEDED)],
                'amount',
            )
            ->get();

        $rows = [];

        foreach ($orders as $order) {
            $currency = (string) $order->currency;
            $recorded = (float) $order->paid_total;
            $expected = (float) ($order->settled_sum ?? 0);

            if (abs($recorded - $expected) < 0.0001) {
                continue;
            }

            $rows[] = [
                'order_number' => (string) $order->order_number,
                'currency' => $currency,
                'recorded' => Currency::round($recorded, $currency),
                'expected' => Currency::round($expected, $currency),
                'difference' => Currency::round($expected - $recorded, $currency),
            ];
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * The order query every report shares, optionally restricted to a set of
     * branches. `null` means "every order of the verified tenant".
     *
     * @param  list<string>|null  $branchIds
     * @return Builder<Order>
     */
    private function orders(?array $branchIds = null): Builder
    {
        return Order::query()->when(
            $branchIds !== null,
            fn (Builder $query): Builder => $query->whereIn('branch_id', $branchIds),
        );
    }
}
