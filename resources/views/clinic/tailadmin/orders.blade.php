@extends('principal.layouts.app', ['title' => __('lba.commerce.orders.plural'), 'panel' => 'clinic'])

@php use Modules\Commerce\Support\Currency; @endphp

@section('content')
    <section class="principal-card">
        <form method="get" action="{{ route('clinic.tailadmin.orders') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <label class="grid gap-1 text-sm">
                {{ __('principal.clinic.search') }}
                <input type="search" name="search" value="{{ $search }}" maxlength="255" class="principal-input">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.orders.status') }}
                <select class="principal-input" name="status">
                    <option value="">—</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.orders.branch') }}
                <select class="principal-input" name="branch">
                    <option value="">—</option>
                    @foreach ($branchOptions as $value => $label)
                        <option value="{{ $value }}" @selected($branch === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button class="principal-button">{{ __('principal.brands.apply') }}</button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="p-4">{{ __('lba.commerce.orders.number') }}</th>
                        <th class="p-4">{{ __('lba.commerce.orders.branch') }}</th>
                        <th class="p-4">{{ __('lba.commerce.orders.customer') }}</th>
                        <th class="p-4">{{ __('lba.commerce.orders.total') }}</th>
                        <th class="p-4">{{ __('lba.commerce.orders.status') }}</th>
                        <th class="p-4">{{ __('lba.commerce.orders.fulfillment') }}</th>
                        <th class="p-4">{{ __('lba.commerce.orders.placed_at') }}</th>
                        <th class="p-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($orders as $order)
                        <tr>
                            <td class="p-4 font-medium">{{ $order->order_number }}</td>
                            <td class="p-4 text-slate-600">{{ $order->branch?->name ?? '—' }}</td>
                            <td class="p-4 text-slate-600">{{ $order->patient?->full_name ?? ($order->customer_name ?: '—') }}</td>
                            <td class="p-4">{{ Currency::format((float) $order->grand_total, (string) $order->currency) }}</td>
                            <td class="p-4">{{ __('lba.commerce.orders.status_'.$order->status) }}</td>
                            <td class="p-4">{{ __('lba.commerce.orders.fulfillment_'.$order->fulfillment_status) }}</td>
                            <td class="p-4 text-slate-600">{{ $order->placed_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                            <td class="p-4">
                                <a class="principal-button" href="{{ route('clinic.tailadmin.orders.show', $order) }}">{{ __('principal.clinic.open') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-8 text-center text-slate-500">
                                <p class="font-medium">{{ __('lba.commerce.orders.empty_heading') }}</p>
                                <p class="text-sm">{{ __('lba.commerce.orders.empty_description') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($orders->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $orders->links() }}</div>
        @endif
    </section>
@endsection
