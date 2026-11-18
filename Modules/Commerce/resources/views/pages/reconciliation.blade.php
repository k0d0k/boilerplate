@php
    $summary = $this->summary();
    $byMerchant = $this->byMerchant();
    $discrepancies = $this->discrepancies();
@endphp

<x-filament-panels::page>
    <style>
        .lba-recon { --lba-navy: #14263d; --lba-teal: #087f80; --lba-bright: #7fe3e0; --lba-line: rgba(255,255,255,0.12); --lba-fg: rgba(255,255,255,0.94); --lba-muted: rgba(255,255,255,0.6); display: flex; flex-direction: column; gap: 1rem; }
        .lba-recon__metrics { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
        .lba-recon__metric { background: var(--lba-navy); border: 1px solid var(--lba-line); border-radius: 0.75rem; padding: 1rem; color: var(--lba-fg); }
        .lba-recon__metric-ccy { font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.14em; color: var(--lba-bright); }
        .lba-recon__metric-value { font-size: 1.5rem; font-weight: 700; color: #fff; margin-top: 0.25rem; }
        .lba-recon__metric-sub { font-size: 0.75rem; color: var(--lba-muted); margin-top: 0.25rem; }
        .lba-recon__block { background: var(--lba-navy); border: 1px solid var(--lba-line); border-radius: 0.75rem; padding: 1rem; color: var(--lba-fg); }
        .lba-recon__block-title { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--lba-muted); margin-bottom: 0.625rem; }
        .lba-recon__table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        .lba-recon__table th { text-align: left; padding: 0.5rem 0.625rem; border-bottom: 1px solid var(--lba-line); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--lba-muted); }
        .lba-recon__table td { padding: 0.5rem 0.625rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .lba-recon__badge { display: inline-flex; align-items: center; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; background: rgba(127,227,224,0.16); color: var(--lba-bright); }
        .lba-recon__empty { font-size: 0.8125rem; color: var(--lba-muted); }
    </style>

    <div class="lba-recon">
        <div class="lba-recon__metrics">
            @foreach ($summary as $currency => $row)
                <div class="lba-recon__metric">
                    <p class="lba-recon__metric-ccy">{{ $currency }}</p>
                    <p class="lba-recon__metric-value">{{ \Modules\Commerce\Support\Currency::format($row['collected'], $currency) }}</p>
                    <p class="lba-recon__metric-sub">
                        {{ __('lba.commerce.reconciliation.refunded') }}: {{ \Modules\Commerce\Support\Currency::format($row['refunded'], $currency) }}
                    </p>
                    <p class="lba-recon__metric-sub">
                        {{ __('lba.commerce.reconciliation.outstanding') }}: {{ \Modules\Commerce\Support\Currency::format($row['outstanding'], $currency) }}
                    </p>
                    <p class="lba-recon__metric-sub">
                        {{ __('lba.commerce.reconciliation.order_count', ['orders' => $row['orders'], 'settled' => $row['settled_orders']]) }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="lba-recon__block">
            <p class="lba-recon__block-title">{{ __('lba.commerce.reconciliation.merchants') }}</p>
            @if ($byMerchant === [])
                <p class="lba-recon__empty">{{ __('lba.commerce.reconciliation.no_merchants') }}</p>
            @else
                <table class="lba-recon__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.commerce.reconciliation.merchant') }}</th>
                            <th>{{ __('lba.commerce.orders.currency') }}</th>
                            <th>{{ __('lba.commerce.reconciliation.collected') }}</th>
                            <th>{{ __('lba.commerce.reconciliation.refunded') }}</th>
                            <th>{{ __('lba.commerce.orders.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byMerchant as $row)
                            <tr>
                                <td>{{ $row['merchant'] }}</td>
                                <td>{{ $row['currency'] }}</td>
                                <td>{{ $this->format($row['collected'], $row['currency']) }}</td>
                                <td>{{ $this->format($row['refunded'], $row['currency']) }}</td>
                                <td>{{ $row['orders'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="lba-recon__block">
            <p class="lba-recon__block-title">{{ __('lba.commerce.reconciliation.discrepancies') }}</p>
            @if ($discrepancies === [])
                <p class="lba-recon__empty">{{ __('lba.commerce.reconciliation.no_discrepancies') }}</p>
            @else
                <table class="lba-recon__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.commerce.orders.number') }}</th>
                            <th>{{ __('lba.commerce.reconciliation.recorded') }}</th>
                            <th>{{ __('lba.commerce.reconciliation.expected') }}</th>
                            <th>{{ __('lba.commerce.reconciliation.difference') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($discrepancies as $row)
                            <tr>
                                <td>{{ $row['order_number'] }}</td>
                                <td>{{ \Modules\Commerce\Support\Currency::format($row['recorded'], $row['currency']) }}</td>
                                <td>{{ \Modules\Commerce\Support\Currency::format($row['expected'], $row['currency']) }}</td>
                                <td><span class="lba-recon__badge">{{ \Modules\Commerce\Support\Currency::format($row['difference'], $row['currency']) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
