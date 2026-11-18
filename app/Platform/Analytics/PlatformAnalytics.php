<?php

namespace App\Platform\Analytics;

use App\Models\Tenant;
use App\Platform\AiBilling\Models\AiUsageRecord;
use App\Platform\AiBilling\Models\TenantAiWallet;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Tenancy\Models\TenantDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Consultations\Models\SinaUsageRecord;

/**
 * WS4 — the central, NON-CLINICAL platform analytics service.
 *
 * Every figure is computed from CENTRAL governance tables only: tenants,
 * tenant-database registry, subscriptions/plans, AI + SINA credit accounting and
 * the redacted module audit log. It never opens a tenant clinical connection, so
 * a Principal analyst can never read a patient, an examination, an image or a
 * finding through this surface.
 *
 * Credits are INTEGER accounting units; no floating-point credit arithmetic is
 * performed here. Money is treated as an exact decimal string and only summed
 * in {@see self::monthlyRecurringRevenue()} to a currency-level total.
 */
final class PlatformAnalytics
{
    // ------------------------------------------------------------- Partners

    /**
     * @return array{total: int, active: int, suspended: int}
     */
    public function partners(): array
    {
        return [
            'total' => Tenant::query()->count(),
            'active' => Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->count(),
            'suspended' => Tenant::query()->where('status', Tenant::STATUS_SUSPENDED)->count(),
        ];
    }

    /**
     * Tenant isolated-database readiness (the ARCH-04 registry only).
     *
     * @return array{migrated: int, pending: int, failed: int}
     */
    public function tenantDatabases(): array
    {
        return [
            'migrated' => TenantDatabase::query()->where('status', TenantDatabase::STATUS_MIGRATED)->count(),
            'pending' => TenantDatabase::query()->whereIn('status', [
                TenantDatabase::STATUS_PENDING,
                TenantDatabase::STATUS_PROVISIONED,
            ])->count(),
            'failed' => TenantDatabase::query()->where('status', TenantDatabase::STATUS_FAILED)->count(),
        ];
    }

    /**
     * @return array{active: int, pending: int, other: int}
     */
    public function subscriptions(): array
    {
        return [
            'active' => TenantSubscription::query()->whereIn('status', [
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_TRIALING,
            ])->count(),
            'pending' => TenantSubscription::query()->where('status', TenantSubscription::STATUS_PENDING)->count(),
            'other' => TenantSubscription::query()->whereIn('status', [
                TenantSubscription::STATUS_PAST_DUE,
                TenantSubscription::STATUS_CANCELLED,
                TenantSubscription::STATUS_EXPIRED,
            ])->count(),
        ];
    }

    // -------------------------------------------------------------- Credits

    /**
     * Aggregate AI credit position across every tenant wallet (integers only).
     *
     * @return array{available: int, reserved: int, consumed: int}
     */
    public function aiCredits(): array
    {
        return [
            'available' => (int) TenantAiWallet::query()->sum('available_credits'),
            'reserved' => (int) TenantAiWallet::query()->sum('reserved_credits'),
            'consumed' => (int) TenantAiWallet::query()->sum('lifetime_consumed_credits'),
        ];
    }

    /**
     * New partners per month (sparkline for the KPI strip).
     *
     * @return list<int>
     */
    public function partnerGrowth(int $months = 8): array
    {
        return $this->newRecordsByMonth(Tenant::class, $months);
    }

    /**
     * Migrated tenant databases per month.
     *
     * @return list<int>
     */
    public function databaseGrowth(int $months = 8): array
    {
        return $this->newRecordsByMonth(TenantDatabase::class, $months, 'last_migrated_at');
    }

    /**
     * New subscriptions per month.
     *
     * @return list<int>
     */
    public function subscriptionGrowth(int $months = 8): array
    {
        return $this->newRecordsByMonth(TenantSubscription::class, $months);
    }

    // -------------------------------------------------------------- Revenue

    /**
     * Current monthly recurring revenue per currency from LIVE subscriptions.
     *
     * A subscription's monthly value is the plan price normalised to a month
     * (yearly prices are divided by twelve); seats are a licence allowance, not
     * a billing multiplier, so they do not scale the price.
     *
     * @return array<string, float> currency => MRR
     */
    public function monthlyRecurringRevenue(): array
    {
        $totals = [];

        foreach ($this->activeSubscriptions() as $subscription) {
            $plan = $subscription->plan;

            if (! $plan instanceof SubscriptionPlan) {
                continue;
            }

            $currency = strtoupper((string) ($plan->currency ?: 'IDR'));
            $totals[$currency] = ($totals[$currency] ?? 0.0) + $this->monthlyValue($plan);
        }

        ksort($totals);

        return $totals;
    }

    /**
     * Active-licence revenue broken down by plan (for the analytics table).
     *
     * @return list<array{plan: string, currency: string, subscriptions: int, seats: int, mrr: float}>
     */
    public function revenueByPlan(): array
    {
        $rows = [];

        foreach ($this->activeSubscriptions() as $subscription) {
            $plan = $subscription->plan;

            if (! $plan instanceof SubscriptionPlan) {
                continue;
            }

            $key = (string) $plan->getKey();
            $rows[$key] ??= [
                'plan' => (string) $plan->name,
                'currency' => strtoupper((string) ($plan->currency ?: 'IDR')),
                'subscriptions' => 0,
                'seats' => 0,
                'mrr' => 0.0,
            ];

            $rows[$key]['subscriptions']++;
            $rows[$key]['seats'] += (int) $subscription->seats;
            $rows[$key]['mrr'] += $this->monthlyValue($plan);
        }

        usort($rows, static fn (array $a, array $b): int => $b['mrr'] <=> $a['mrr']);

        return array_values($rows);
    }

    /**
     * Monthly recurring revenue by currency for the last N months.
     *
     * A subscription contributes to a month when its live window covers that
     * month (starts_at before the month end and not yet cancelled/ended).
     *
     * @return array{labels: list<string>, series: array<string, list<float>>, currencies: list<string>}
     */
    public function revenueTrend(int $months = 6): array
    {
        $months = max(1, $months);
        $labels = $this->monthLabels($months);
        $subscriptions = TenantSubscription::query()->with('plan')->get();

        $series = [];

        foreach ($subscriptions as $subscription) {
            $plan = $subscription->plan;

            if (! $plan instanceof SubscriptionPlan) {
                continue;
            }

            $currency = strtoupper((string) ($plan->currency ?: 'IDR'));
            $series[$currency] ??= array_fill(0, $months, 0.0);

            for ($i = 0; $i < $months; $i++) {
                $start = now()->startOfMonth()->subMonths($months - 1 - $i);
                $end = $start->copy()->endOfMonth();

                if ($this->covers($subscription, $start, $end)) {
                    $series[$currency][$i] += $this->monthlyValue($plan);
                }
            }
        }

        ksort($series);

        return [
            'labels' => $labels,
            'series' => $series,
            'currencies' => array_keys($series),
        ];
    }

    // ---------------------------------------------------------------- Usage

    /**
     * Monthly AI (LBA) and SINA credit consumption — non-identifying totals.
     *
     * @return array{labels: list<string>, ai: list<int>, sina: list<int>}
     */
    public function usageTrend(int $months = 6): array
    {
        $months = max(1, $months);

        return [
            'labels' => $this->monthLabels($months),
            'ai' => $this->consumedByMonth(AiUsageRecord::class, $months),
            'sina' => $this->consumedByMonth(SinaUsageRecord::class, $months),
        ];
    }

    // ------------------------------------------------------------- Activity

    /**
     * The most recent redacted module lifecycle events (never clinical).
     *
     * @return Collection<int, ModuleAuditLog>
     */
    public function recentModuleActivity(int $limit = 5): Collection
    {
        return ModuleAuditLog::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    // ------------------------------------------------------------- Internals

    /**
     * @return Collection<int, TenantSubscription>
     */
    private function activeSubscriptions(): Collection
    {
        return TenantSubscription::query()
            ->with('plan')
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIALING])
            ->whereNull('cancelled_at')
            ->whereNull('ended_at')
            ->get();
    }

    private function monthlyValue(SubscriptionPlan $plan): float
    {
        $price = (float) $plan->price_amount;

        return $plan->billing_interval === SubscriptionPlan::INTERVAL_YEARLY
            ? round($price / 12, 2)
            : round($price, 2);
    }

    private function covers(TenantSubscription $subscription, Carbon $start, Carbon $end): bool
    {
        $startsAt = $subscription->starts_at ?? $subscription->created_at;

        if ($startsAt !== null && $startsAt->greaterThan($end)) {
            return false;
        }

        if ($subscription->ended_at !== null && $subscription->ended_at->lessThan($start)) {
            return false;
        }

        if ($subscription->cancelled_at !== null && $subscription->cancelled_at->lessThan($start)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function monthLabels(int $months): array
    {
        $locale = app()->getLocale();
        $labels = [];
        $cursor = now()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $labels[] = $cursor->copy()->locale($locale)->translatedFormat('M Y');
            $cursor->addMonth();
        }

        return $labels;
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<int>
     */
    private function newRecordsByMonth(string $model, int $months, string $column = 'created_at'): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $totals = array_fill(0, $months, 0);

        $rows = $model::query()
            ->where($column, '>=', $start)
            ->get([$column]);

        foreach ($rows as $row) {
            $value = $row->getAttribute($column);

            if ($value === null) {
                continue;
            }

            $value = $value instanceof Carbon ? $value : Carbon::parse((string) $value);
            $offset = (int) $start->diffInMonths($value->copy()->startOfMonth());

            if ($offset >= 0 && $offset < $months) {
                $totals[$offset]++;
            }
        }

        return $totals;
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<int>
     */
    private function consumedByMonth(string $model, int $months): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $totals = array_fill(0, $months, 0);

        $rows = $model::query()
            ->where('recorded_at', '>=', $start)
            ->get(['recorded_at', 'credit_units']);

        foreach ($rows as $row) {
            $recordedAt = $row->getAttribute('recorded_at');

            if ($recordedAt === null) {
                continue;
            }

            $recordedAt = $recordedAt instanceof Carbon ? $recordedAt : Carbon::parse((string) $recordedAt);
            $offset = (int) $start->diffInMonths($recordedAt->copy()->startOfMonth());

            if ($offset >= 0 && $offset < $months) {
                $totals[$offset] += (int) $row->getAttribute('credit_units');
            }
        }

        return $totals;
    }
}
