@extends('principal.layouts.app', ['title' => __('lba.commerce.products.plural'), 'panel' => 'clinic'])

@php use Modules\Commerce\Support\Currency; @endphp

@section('content')
    <section class="principal-card">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-4">
            <form method="get" action="{{ route('clinic.tailadmin.products') }}" class="flex min-w-0 flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm">
                    {{ __('principal.clinic.search') }}
                    <input type="search" name="search" value="{{ $search }}" maxlength="255" class="principal-input">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.products.status') }}
                    <select class="principal-input" name="status">
                        <option value="">—</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="principal-button">{{ __('principal.brands.apply') }}</button>
            </form>
            @if ($canCreate)
                <a class="principal-button principal-button-primary" href="{{ route('clinic.tailadmin.products.create') }}">{{ __('lba.commerce.products.add') }}</a>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="p-4">{{ __('lba.commerce.products.name') }}</th>
                        <th class="p-4">{{ __('lba.commerce.products.sku') }}</th>
                        <th class="p-4">{{ __('lba.commerce.products.category') }}</th>
                        <th class="p-4">{{ __('lba.commerce.products.base_price') }}</th>
                        <th class="p-4">{{ __('lba.commerce.products.status') }}</th>
                        <th class="p-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($products as $product)
                        <tr>
                            <td class="wrap-break-word p-4 font-medium">{{ $product->name }}</td>
                            <td class="p-4 text-slate-600">{{ $product->sku ?: '—' }}</td>
                            <td class="p-4 text-slate-600">{{ $product->category?->name ?? '—' }}</td>
                            <td class="p-4">{{ Currency::format((float) $product->base_price, (string) $product->base_currency) }}</td>
                            <td class="p-4">{{ __('lba.commerce.products.status_'.$product->status) }}</td>
                            <td class="p-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a class="principal-button" href="{{ route('clinic.tailadmin.products.edit', $product) }}">{{ __('lba.commerce.products.edit') }}</a>
                                    @if (! $product->isArchived())
                                        <form method="post" action="{{ route('clinic.tailadmin.products.archive', $product) }}">
                                            @csrf
                                            <button class="principal-button" type="submit">{{ __('lba.commerce.products.archive') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-500">
                                <p class="font-medium">{{ __('lba.commerce.products.empty_heading') }}</p>
                                <p class="text-sm">{{ __('lba.commerce.products.empty_description') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $products->links() }}</div>
        @endif
    </section>
@endsection
