@extends('principal.layouts.app', ['title' => __('lba.commerce.orders.single').' '.$order->order_number, 'panel' => 'clinic'])

@php use Modules\Commerce\Support\Currency; @endphp

@section('content')
    <div class="grid gap-5">
        @if (session('order_redirect_url'))
            <p class="principal-card p-4 text-sm">
                {{ __('lba.commerce.pos.provider_ready') }}
                <a class="text-navy-900 underline" href="{{ session('order_redirect_url') }}" rel="noopener">{{ session('order_redirect_url') }}</a>
            </p>
        @endif

        <section class="principal-card grid gap-3 p-5 sm:grid-cols-2">
            <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold text-navy-900">{{ $order->order_number }}</h2>
                <a class="principal-button" href="{{ route('clinic.tailadmin.orders') }}">{{ __('lba.commerce.orders.back') }}</a>
            </div>
            <p class="text-sm"><span class="text-slate-500">{{ __('lba.commerce.orders.branch') }}:</span> {{ $order->branch?->name ?? '—' }}</p>
            <p class="text-sm"><span class="text-slate-500">{{ __('lba.commerce.orders.customer') }}:</span> {{ $order->patient?->full_name ?? ($order->customer_name ?: '—') }}</p>
            <p class="text-sm"><span class="text-slate-500">{{ __('lba.commerce.orders.status') }}:</span> {{ __('lba.commerce.orders.status_'.$order->status) }}</p>
            <p class="text-sm"><span class="text-slate-500">{{ __('lba.commerce.orders.fulfillment') }}:</span> {{ __('lba.commerce.orders.fulfillment_'.$order->fulfillment_status) }}</p>
            <p class="text-sm"><span class="text-slate-500">{{ __('lba.commerce.orders.total') }}:</span> {{ Currency::format((float) $order->grand_total, (string) $order->currency) }}</p>
            <p class="text-sm"><span class="text-slate-500">{{ __('lba.commerce.orders.paid') }}:</span> {{ Currency::format((float) $order->paid_total, (string) $order->currency) }}</p>
            <p class="text-sm"><span class="text-slate-500">{{ __('lba.commerce.orders.placed_at') }}:</span> {{ $order->placed_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
            <p class="text-sm"><span class="text-slate-500">{{ __('lba.commerce.orders.source') }}:</span> {{ __('lba.commerce.orders.source_'.$order->source) }}</p>
        </section>

        <section class="principal-card p-5">
            <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.commerce.orders.items_title') }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="p-3">{{ __('lba.commerce.orders.item_name') }}</th>
                            <th class="p-3">{{ __('lba.commerce.orders.item_sku') }}</th>
                            <th class="p-3">{{ __('lba.commerce.orders.unit_price') }}</th>
                            <th class="p-3">{{ __('lba.commerce.orders.quantity') }}</th>
                            <th class="p-3">{{ __('lba.commerce.orders.line_total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="wrap-break-word p-3">{{ $item->name }}</td>
                                <td class="p-3 text-slate-600">{{ $item->sku ?: '—' }}</td>
                                <td class="p-3">{{ Currency::format((float) $item->unit_price, (string) $item->currency) }}</td>
                                <td class="p-3">{{ (int) $item->quantity }}</td>
                                <td class="p-3">{{ Currency::format((float) $item->line_total, (string) $item->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="principal-card p-5">
            <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.commerce.orders.invoices_title') }}</h3>
            <ul class="divide-y divide-slate-100">
                @forelse ($order->invoices as $invoice)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span class="font-medium">{{ $invoice->number }}</span>
                        <span class="text-slate-600">{{ __('lba.commerce.orders.invoice_status_'.$invoice->status) }}</span>
                        <span>{{ Currency::format((float) $invoice->amount, (string) $invoice->currency) }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sm text-slate-500">—</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card p-5">
            <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.commerce.orders.transactions_title') }}</h3>
            <ul class="divide-y divide-slate-100">
                @forelse ($order->transactions as $transaction)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span class="font-medium">{{ $transaction->provider }}</span>
                        <span class="text-slate-600">{{ $transaction->provider_reference ?: '—' }}</span>
                        <span class="text-slate-600">{{ __('lba.commerce.orders.payment_status_'.$transaction->status) }}</span>
                        <span>{{ Currency::format((float) $transaction->amount, (string) $transaction->currency) }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sm text-slate-500">—</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card flex flex-wrap items-end gap-4 p-5">
            @if ($canPay)
                <form method="post" action="{{ route('clinic.tailadmin.orders.pay', $order) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.commerce.orders.provider') }}
                        <select class="principal-input" name="provider" required>
                            @foreach ($providerOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="principal-button principal-button-primary" type="submit">{{ __('lba.commerce.orders.pay_online') }}</button>
                </form>
            @endif

            @if ($canFulfil)
                <form method="post" action="{{ route('clinic.tailadmin.orders.fulfil', $order) }}">
                    @csrf
                    <button class="principal-button principal-button-primary" type="submit">{{ __('lba.commerce.orders.fulfil') }}</button>
                </form>
            @endif

            @if ($canCancel)
                <form method="post" action="{{ route('clinic.tailadmin.orders.cancel', $order) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.commerce.orders.cancel_reason') }}
                        <input class="principal-input" name="reason" maxlength="500">
                    </label>
                    <button class="principal-button" type="submit">{{ __('lba.commerce.orders.cancel') }}</button>
                </form>
            @endif

            @if ($canRefund)
                <form method="post" action="{{ route('clinic.tailadmin.orders.refund', $order) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.commerce.orders.refund_reason') }}
                        <input class="principal-input" name="reason" maxlength="500">
                    </label>
                    <button class="principal-button" type="submit">{{ __('lba.commerce.orders.refund') }}</button>
                </form>
            @endif
        </section>
    </div>
@endsection
