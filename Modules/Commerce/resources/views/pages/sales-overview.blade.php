@php
    $summary = $this->summary();
    $breakdown = $this->statusBreakdown();
    $recent = $this->recentOrders();
    $top = $this->topProducts();
    $stockValue = $this->inventoryValue();
    $lowStock = $this->lowStockCount();
@endphp

<x-filament-panels::page>
    <style>
        .lba-sales { --lba-navy: #14263d; --lba-navy-2: #1c3550; --lba-teal: #087f80; --lba-bright: #7fe3e0; --lba-line: rgba(255,255,255,0.12); --lba-fg: rgba(255,255,255,0.94); --lba-muted: rgba(255,255,255,0.6); display: flex; flex-direction: column; gap: 1rem; }
        .lba-sales__metrics { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
        .lba-sales__metric { background: var(--lba-navy); border: 1px solid var(--lba-line); border-radius: 0.75rem; padding: 1rem; color: var(--lba-fg); }
        .lba-sales__metric-ccy { font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.14em; color: var(--lba-bright); }
        .lba-sales__metric-value { font-size: 1.5rem; font-weight: 700; color: #fff; margin-top: 0.25rem; }
        .lba-sales__metric-sub { font-size: 0.75rem; color: var(--lba-muted); margin-top: 0.25rem; }
        .lba-sales__block { background: var(--lba-navy); border: 1px solid var(--lba-line); border-radius: 0.75rem; padding: 1rem; color: var(--lba-fg); }
        .lba-sales__block-title { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--lba-muted); margin-bottom: 0.625rem; }
        .lba-sales__table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        .lba-sales__table th { text-align: left; padding: 0.5rem 0.625rem; border-bottom: 1px solid var(--lba-line); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--lba-muted); }
        .lba-sales__table td { padding: 0.5rem 0.625rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .lba-sales__badge { display: inline-flex; align-items: center; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; background: rgba(127,227,224,0.16); color: var(--lba-bright); }
        .lba-sales__empty { font-size: 0.8125rem; color: var(--lba-muted); }
        .lba-sales__cols { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); }
        .lba-sales__chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .lba-sales__chip { border: 1px solid var(--lba-line); border-radius: 0.5rem; padding: 0.375rem 0.625rem; font-size: 0.75rem; color: var(--lba-fg); }
    </style>

    <div class="lba-sales">
        <div class="lba-sales__metrics">
            @foreach ($summary as $currency => $row)
                <div class="lba-sales__metric">
                    <p class="lba-sales__metric-ccy">{{ $currency }}</p>
                    <p class="lba-sales__metric-value">{{ $this->format($row['sales'], $currency) }}</p>
                    <p class="lba-sales__metric-sub">
                        {{ __('lba.commerce.sales.orders_count', ['count' => $row['orders'], 'paid' => $row['paid']]) }}
                    </p>
                    <p class="lba-sales__metric-sub">
                        {{ __('lba.commerce.sales.outstanding') }}: {{ $this->format($row['outstanding'], $currency) }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="lba-sales__block">
            <p class="lba-sales__block-title">{{ __('lba.commerce.sales.status_breakdown') }}</p>
            <div class="lba-sales__chips">
                @foreach ($breakdown as $status => $count)
                    <span class="lba-sales__chip">{{ __('lba.commerce.orders.status_'.$status) }}: <strong>{{ $count }}</strong></span>
                @endforeach
            </div>
        </div>

        <div class="lba-sales__block">
            <p class="lba-sales__block-title">{{ __('lba.commerce.sales.inventory') }}</p>
            <div class="lba-sales__chips">
                <span class="lba-sales__chip">{{ __('lba.commerce.sales.low_stock') }}: <strong>{{ $lowStock }}</strong></span>
                @forelse ($stockValue as $currency => $value)
                    <span class="lba-sales__chip">{{ __('lba.commerce.sales.stock_value') }} ({{ $currency }}): <strong>{{ $this->format($value, $currency) }}</strong></span>
                @empty
                    <span class="lba-sales__empty">{{ __('lba.commerce.sales.no_inventory') }}</span>
                @endforelse
            </div>
        </div>

        <div class="lba-sales__cols">
            <div class="lba-sales__block">
                <p class="lba-sales__block-title">{{ __('lba.commerce.sales.recent_orders') }}</p>
                @if ($recent->isEmpty())
                    <p class="lba-sales__empty">{{ __('lba.commerce.sales.no_orders') }}</p>
                @else
                    <table class="lba-sales__table">
                        <thead>
                            <tr>
                                <th>{{ __('lba.commerce.orders.number') }}</th>
                                <th>{{ __('lba.commerce.orders.total') }}</th>
                                <th>{{ __('lba.commerce.orders.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recent as $order)
                                <tr>
                                    <td><a href="{{ \Modules\Commerce\Filament\Pages\SalesOverview::orderUrl($order) }}" style="color: var(--lba-bright);">{{ $order->order_number }}</a></td>
                                    <td>{{ $this->format((float) $order->grand_total, (string) $order->currency) }}</td>
                                    <td><span class="lba-sales__badge">{{ __('lba.commerce.orders.status_'.$order->status) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="lba-sales__block">
                <p class="lba-sales__block-title">{{ __('lba.commerce.sales.top_products') }}</p>
                @if ($top->isEmpty())
                    <p class="lba-sales__empty">{{ __('lba.commerce.sales.no_products') }}</p>
                @else
                    <table class="lba-sales__table">
                        <thead>
                            <tr>
                                <th>{{ __('lba.commerce.orders.item_name') }}</th>
                                <th>{{ __('lba.commerce.orders.quantity') }}</th>
                                <th>{{ __('lba.commerce.orders.line_total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($top as $row)
                                <tr>
                                    <td>{{ $row->name }}</td>
                                    <td>{{ (int) $row->sold_quantity }}</td>
                                    <td>{{ $this->format((float) $row->sold_total, (string) $row->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
