@extends('principal.layouts.app', [
    'title' => $product ? __('lba.commerce.products.edit') : __('lba.commerce.products.add'),
    'panel' => 'clinic',
])

@section('content')
    @php($action = $product ? route('clinic.tailadmin.products.update', $product) : route('clinic.tailadmin.products.store'))

    <form method="post" action="{{ $action }}" class="grid gap-5">
        @csrf
        @if ($product)
            @method('put')
        @endif

        <section class="principal-card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="font-semibold text-navy-900 sm:col-span-2">{{ __('lba.commerce.products.section_identity') }}</h2>

            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.name') }}
                <input class="principal-input" name="name" maxlength="255" required value="{{ old('name', $product?->name) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.sku') }}
                <input class="principal-input" name="sku" maxlength="255" value="{{ old('sku', $product?->sku) }}">
                <span class="text-xs text-slate-500">{{ __('lba.commerce.products.sku_help') }}</span>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.slug') }}
                <input class="principal-input" name="slug" maxlength="255" value="{{ old('slug', $product?->slug) }}">
                <span class="text-xs text-slate-500">{{ __('lba.commerce.products.slug_help') }}</span>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.category') }}
                <select name="category_id" class="principal-input">
                    <option value="">{{ __('lba.commerce.products.category_none') }}</option>
                    @foreach ($categoryOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('category_id', $product?->category_id) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.kind') }}
                <select name="kind" class="principal-input" required>
                    @foreach ($kindOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('kind', $product?->kind ?? 'product') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.unit') }}
                <input class="principal-input" name="unit" maxlength="20" value="{{ old('unit', $product?->unit ?? 'pcs') }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.status') }}
                <select name="status" class="principal-input" required>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $product?->status ?? 'active') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __('lba.commerce.products.description') }}
                <textarea class="principal-input" name="description" rows="3" maxlength="2000">{{ old('description', $product?->description) }}</textarea>
            </label>
        </section>

        <section class="principal-card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="font-semibold text-navy-900 sm:col-span-2">{{ __('lba.commerce.products.section_pricing') }}</h2>
            <p class="text-xs text-slate-500 sm:col-span-2">{{ __('lba.commerce.products.pricing_notice') }}</p>

            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.base_currency') }}
                <select name="base_currency" class="principal-input" required>
                    @foreach ($currencyOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('base_currency', $product?->base_currency ?? 'IDR') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.products.base_price') }}
                <input class="principal-input" type="number" step="0.01" min="0" name="base_price" required value="{{ old('base_price', $product?->base_price ?? 0) }}">
            </label>
        </section>

        <section class="principal-card grid gap-4 p-5">
            <h2 class="font-semibold text-navy-900">{{ __('lba.commerce.products.section_inventory') }}</h2>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="track_inventory" value="1" @checked(old('track_inventory', $product?->track_inventory ?? true))>
                {{ __('lba.commerce.products.track_inventory') }}
            </label>
            <p class="text-xs text-slate-500">{{ __('lba.commerce.products.track_inventory_help') }}</p>
        </section>

        <div class="flex flex-wrap gap-2">
            <button class="principal-button principal-button-primary" type="submit">{{ __('principal.brands.save') }}</button>
            <a class="principal-button" href="{{ route('clinic.tailadmin.products') }}">{{ __('principal.brands.cancel') }}</a>
        </div>
    </form>
@endsection
