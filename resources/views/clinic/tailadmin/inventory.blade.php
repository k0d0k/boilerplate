@extends('principal.layouts.app', ['title' => __('lba.commerce.inventory.plural'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card">
        <form method="get" action="{{ route('clinic.tailadmin.inventory') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.inventory.filter_branch') }}
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
                        <th class="p-4">{{ __('lba.commerce.inventory.product') }}</th>
                        <th class="p-4">{{ __('lba.commerce.inventory.branch') }}</th>
                        <th class="p-4">{{ __('lba.commerce.inventory.quantity') }}</th>
                        <th class="p-4">{{ __('lba.commerce.inventory.reorder_level') }}</th>
                        <th class="p-4">{{ __('lba.commerce.inventory.low') }}</th>
                        <th class="p-4">{{ __('lba.commerce.inventory.receive') }} / {{ __('lba.commerce.inventory.adjust') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($stock as $row)
                        <tr>
                            <td class="wrap-break-word p-4 font-medium">{{ $row->product?->name ?? '—' }}</td>
                            <td class="p-4 text-slate-600">{{ $row->branch?->name ?? '—' }}</td>
                            <td class="p-4">{{ number_format((int) $row->quantity) }}</td>
                            <td class="p-4 text-slate-600">{{ $row->reorder_level !== null ? number_format((int) $row->reorder_level) : '—' }}</td>
                            <td class="p-4">{{ $row->isLow() ? __('lba.commerce.inventory.low') : '—' }}</td>
                            <td class="p-4">
                                <div class="grid gap-2">
                                    <form method="post" action="{{ route('clinic.tailadmin.inventory.receive', $row) }}" class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        <label class="grid gap-1 text-xs">
                                            {{ __('lba.commerce.inventory.add_quantity') }}
                                            <input class="principal-input" type="number" name="quantity" min="1" value="1" required>
                                        </label>
                                        <label class="grid gap-1 text-xs">
                                            {{ __('lba.commerce.inventory.reason') }}
                                            <input class="principal-input" name="reason" maxlength="255">
                                        </label>
                                        <button class="principal-button" type="submit">{{ __('lba.commerce.inventory.receive') }}</button>
                                    </form>
                                    <form method="post" action="{{ route('clinic.tailadmin.inventory.adjust', $row) }}" class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        <label class="grid gap-1 text-xs">
                                            {{ __('lba.commerce.inventory.new_quantity') }}
                                            <input class="principal-input" type="number" name="quantity" min="0" value="{{ (int) $row->quantity }}" required>
                                        </label>
                                        <label class="grid gap-1 text-xs">
                                            {{ __('lba.commerce.inventory.reason') }}
                                            <input class="principal-input" name="reason" maxlength="255">
                                        </label>
                                        <button class="principal-button" type="submit">{{ __('lba.commerce.inventory.adjust') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-500">
                                <p class="font-medium">{{ __('lba.commerce.inventory.empty_heading') }}</p>
                                <p class="text-sm">{{ __('lba.commerce.inventory.empty_description') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($stock->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $stock->links() }}</div>
        @endif
    </section>
@endsection
