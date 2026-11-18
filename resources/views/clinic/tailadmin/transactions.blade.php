@extends('principal.layouts.app', ['title' => __('lba.commerce.transactions.nav'), 'panel' => 'clinic'])

@php use Modules\Commerce\Support\Currency; @endphp

@section('content')
    <section class="principal-card">
        <form method="get" action="{{ route('clinic.tailadmin.transactions') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.transactions.status') }}
                <select class="principal-input" name="status">
                    <option value="">—</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button class="principal-button">{{ __('lba.commerce.pos.search') }}</button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="p-4">{{ __('lba.commerce.orders.number') }}</th>
                        <th class="p-4">{{ __('lba.commerce.transactions.provider') }}</th>
                        <th class="p-4">{{ __('lba.commerce.transactions.reference') }}</th>
                        <th class="p-4">{{ __('lba.commerce.transactions.amount') }}</th>
                        <th class="p-4">{{ __('lba.commerce.transactions.status') }}</th>
                        <th class="p-4">{{ __('lba.commerce.transactions.recorded_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($transactions as $transaction)
                        <tr>
                            <td class="p-4 font-medium">{{ $transaction->order?->order_number ?? '—' }}</td>
                            <td class="p-4">{{ $transaction->provider }}</td>
                            <td class="wrap-break-word p-4 text-slate-600">{{ $transaction->provider_reference ?: '—' }}</td>
                            <td class="p-4">{{ Currency::format((float) $transaction->amount, (string) $transaction->currency) }}</td>
                            <td class="p-4">{{ __('lba.commerce.orders.payment_status_'.$transaction->status) }}</td>
                            <td class="p-4 text-slate-600">{{ $transaction->created_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-500">
                                <p class="font-medium">{{ __('lba.commerce.transactions.empty_heading') }}</p>
                                <p class="text-sm">{{ __('lba.commerce.transactions.empty_description') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($transactions->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $transactions->links() }}</div>
        @endif
    </section>
@endsection
