@extends('principal.layouts.app', ['title' => __('lba.analytics.title')])

@section('content')
    @php
        $money = static fn (float|int|string $amount, string $currency): string => \Modules\Commerce\Support\Currency::isSupported($currency)
            ? \Modules\Commerce\Support\Currency::format($amount, $currency)
            : number_format((float) $amount, 2).' '.$currency;

        $summary = [
            ['label' => __('lba.analytics.summary_partners'), 'value' => $partners['total'], 'caption' => __('lba.analytics.summary_partners_caption', ['active' => $partners['active'], 'suspended' => $partners['suspended']])],
            ['label' => __('lba.analytics.summary_databases'), 'value' => $databases['migrated'], 'caption' => __('lba.analytics.summary_databases_caption', ['pending' => $databases['pending'], 'failed' => $databases['failed']])],
            ['label' => __('lba.analytics.summary_subscriptions'), 'value' => $subscriptions['active'], 'caption' => __('lba.analytics.summary_subscriptions_caption', ['pending' => $subscriptions['pending'], 'other' => $subscriptions['other']])],
            ['label' => __('lba.analytics.summary_credits'), 'value' => number_format($credits['consumed']), 'caption' => __('lba.analytics.summary_credits_caption', ['available' => number_format($credits['available']), 'reserved' => number_format($credits['reserved'])])],
        ];
    @endphp

    <p class="mb-5 text-sm text-slate-500">{{ __('lba.analytics.subtitle') }}</p>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $item)
            <section class="principal-card p-4">
                <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ $item['label'] }}</p>
                <p class="mt-1 text-2xl font-bold text-navy-900">{{ $item['value'] }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $item['caption'] }}</p>
            </section>
        @endforeach
    </div>

    <section class="principal-card mt-5 p-5">
        <h2 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.analytics.mrr_heading') }}</h2>

        @if (empty($mrr))
            <p class="mt-3 text-sm text-slate-500">{{ __('lba.analytics.mrr_empty') }}</p>
        @else
            <div class="mt-3 flex flex-wrap gap-3">
                @foreach ($mrr as $currency => $amount)
                    <div class="min-w-36 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ $currency }}</p>
                        <p class="mt-1 text-lg font-bold text-brand-700">{{ $money($amount, (string) $currency) }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="principal-card mt-5">
        <h2 class="border-b border-slate-200 p-4 font-semibold text-navy-900">{{ __('lba.analytics.revenue_by_plan_heading') }}</h2>

        @if (empty($plans))
            <p class="p-4 text-sm text-slate-500">{{ __('lba.analytics.revenue_by_plan_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            @foreach (['col_plan', 'col_currency', 'col_subscriptions', 'col_seats', 'col_mrr'] as $column)
                                <th scope="col" class="px-4 py-3 text-start">{{ __('lba.analytics.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($plans as $row)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $row['plan'] }}</td>
                                <td class="px-4 py-3">{{ $row['currency'] }}</td>
                                <td class="px-4 py-3">{{ $row['subscriptions'] }}</td>
                                <td class="px-4 py-3">{{ $row['seats'] }}</td>
                                <td class="px-4 py-3">{{ $money($row['mrr'], $row['currency']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
