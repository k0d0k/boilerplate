@php
    use Modules\Commerce\Support\Currency;

    $partners = $this->partners();
    $databases = $this->tenantDatabases();
    $subscriptions = $this->subscriptions();
    $credits = $this->aiCredits();
    $mrr = $this->monthlyRecurringRevenue();
    $plans = $this->revenueByPlan();

    $money = static function (float|int|string $amount, string $currency): string {
        return Currency::isSupported($currency)
            ? Currency::format($amount, $currency)
            : number_format((float) $amount, 2).' '.$currency;
    };

    $summary = [
        ['label' => __('lba.analytics.summary_partners'), 'value' => $partners['total'], 'caption' => __('lba.analytics.summary_partners_caption', ['active' => $partners['active'], 'suspended' => $partners['suspended']])],
        ['label' => __('lba.analytics.summary_databases'), 'value' => $databases['migrated'], 'caption' => __('lba.analytics.summary_databases_caption', ['pending' => $databases['pending'], 'failed' => $databases['failed']])],
        ['label' => __('lba.analytics.summary_subscriptions'), 'value' => $subscriptions['active'], 'caption' => __('lba.analytics.summary_subscriptions_caption', ['pending' => $subscriptions['pending'], 'other' => $subscriptions['other']])],
        ['label' => __('lba.analytics.summary_credits'), 'value' => number_format($credits['consumed']), 'caption' => __('lba.analytics.summary_credits_caption', ['available' => number_format($credits['available']), 'reserved' => number_format($credits['reserved'])])],
    ];
@endphp

<x-filament-panels::page>
    <style>
        .lba-analytics { --lba-an-bg:#0c1725; --lba-an-panel:#0f1d2f; --lba-an-line:rgba(255,255,255,0.10); --lba-an-fg:rgba(255,255,255,0.92); --lba-an-muted:rgba(255,255,255,0.55); --lba-an-bright:#7fe3e0; background:var(--lba-an-bg); border:1px solid var(--lba-an-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-an-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-analytics__grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(12rem, 1fr)); gap:0.75rem; }
        .lba-analytics__card { padding:0.875rem; border:1px solid var(--lba-an-line); border-radius:0.625rem; background:var(--lba-an-panel); }
        .lba-analytics__card-label { font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-an-muted); }
        .lba-analytics__card-value { font-size:1.5rem; font-weight:700; margin-top:0.25rem; }
        .lba-analytics__card-caption { font-size:0.75rem; color:var(--lba-an-muted); margin-top:0.25rem; }
        .lba-analytics__block { padding:0.875rem; border:1px solid var(--lba-an-line); border-radius:0.625rem; background:var(--lba-an-panel); }
        .lba-analytics__block-title { font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-an-muted); margin-bottom:0.625rem; }
        .lba-analytics__mrr { display:flex; flex-wrap:wrap; gap:0.75rem; }
        .lba-analytics__mrr-item { padding:0.625rem 0.875rem; border:1px solid var(--lba-an-line); border-radius:0.5rem; background:#0a1420; min-width:9rem; }
        .lba-analytics__mrr-value { font-size:1.25rem; font-weight:700; color:var(--lba-an-bright); }
        .lba-analytics__table { width:100%; border-collapse:collapse; font-size:0.8125rem; }
        .lba-analytics__table th { text-align:left; font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--lba-an-muted); padding:0.375rem 0.5rem; border-bottom:1px solid var(--lba-an-line); }
        .lba-analytics__table td { padding:0.5rem; border-bottom:1px solid rgba(255,255,255,0.06); }
        .lba-analytics__empty { font-size:0.8125rem; color:var(--lba-an-muted); }
    </style>

    <div class="lba-analytics">
        <div class="lba-analytics__grid">
            @foreach ($summary as $item)
                <div class="lba-analytics__card">
                    <p class="lba-analytics__card-label">{{ $item['label'] }}</p>
                    <p class="lba-analytics__card-value">{{ $item['value'] }}</p>
                    <p class="lba-analytics__card-caption">{{ $item['caption'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="lba-analytics__block">
            <p class="lba-analytics__block-title">{{ __('lba.analytics.mrr_heading') }}</p>

            @if (empty($mrr))
                <p class="lba-analytics__empty">{{ __('lba.analytics.mrr_empty') }}</p>
            @else
                <div class="lba-analytics__mrr">
                    @foreach ($mrr as $currency => $amount)
                        <div class="lba-analytics__mrr-item">
                            <p class="lba-analytics__card-label">{{ $currency }}</p>
                            <p class="lba-analytics__mrr-value">{{ $money($amount, (string) $currency) }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="lba-analytics__block">
            <p class="lba-analytics__block-title">{{ __('lba.analytics.revenue_by_plan_heading') }}</p>

            @if (empty($plans))
                <p class="lba-analytics__empty">{{ __('lba.analytics.revenue_by_plan_empty') }}</p>
            @else
                <table class="lba-analytics__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.analytics.col_plan') }}</th>
                            <th>{{ __('lba.analytics.col_currency') }}</th>
                            <th>{{ __('lba.analytics.col_subscriptions') }}</th>
                            <th>{{ __('lba.analytics.col_seats') }}</th>
                            <th>{{ __('lba.analytics.col_mrr') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plans as $row)
                            <tr>
                                <td>{{ $row['plan'] }}</td>
                                <td>{{ $row['currency'] }}</td>
                                <td>{{ $row['subscriptions'] }}</td>
                                <td>{{ $row['seats'] }}</td>
                                <td>{{ $money($row['mrr'], $row['currency']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
