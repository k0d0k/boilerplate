@extends('principal.layouts.app', ['title' => __('lba.commerce.pos.title'), 'panel' => 'clinic'])

@php use Modules\Commerce\Support\Currency; @endphp

@section('content')
    @if (session('pos_redirect_url'))
        <p role="status" class="mb-5 rounded-lg border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-700">
            {{ __('lba.commerce.pos.provider_ready') }}
            <a class="underline" href="{{ session('pos_redirect_url') }}" target="_blank" rel="noopener">{{ session('pos_redirect_url') }}</a>
        </p>
    @endif

    @if ($branchOptions === [])
        <p class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('lba.branch_switcher.choose') }}</p>
    @endif

    <form method="post" action="{{ route('clinic.tailadmin.pos.checkout') }}" class="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">

        <section class="principal-card p-5">
            <h2 class="mb-3 font-semibold text-navy-900">{{ __('lba.commerce.pos.catalog') }}</h2>
            @if ($products->isEmpty())
                <p class="text-sm text-slate-500">{{ __('lba.commerce.pos.no_products') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="py-2">{{ __('lba.commerce.pos.item') }}</th>
                            <th class="py-2">{{ __('lba.commerce.orders.unit_price') }}</th>
                            <th class="w-24 py-2">{{ __('lba.commerce.pos.qty') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($products as $product)
                            @php
                                $idr = $product->priceFor(Currency::IDR);
                                $displayCurrency = $idr !== null ? Currency::IDR : (string) $product->base_currency;
                                $displayPrice = $idr ?? (float) $product->base_price;
                            @endphp
                            <tr>
                                <td class="py-2">
                                    <p class="font-medium">{{ $product->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $product->sku ?? '—' }}</p>
                                </td>
                                <td class="py-2">{{ Currency::format($displayPrice, $displayCurrency) }}</td>
                                <td class="py-2">
                                    <input type="number" min="0" class="principal-input"
                                        name="quantities[{{ $product->getKey() }}]"
                                        value="{{ (int) old('quantities.'.$product->getKey(), 0) }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="principal-card grid content-start gap-3 p-5">
            <h2 class="font-semibold text-navy-900">{{ __('lba.commerce.pos.cart') }}</h2>

            <label class="grid gap-1 text-sm">
                {{ __('lba.commerce.pos.branch') }}
                <select class="principal-input" name="branch_id" required>
                    @foreach ($branchOptions as $option)
                        <option value="{{ $option->id }}" @selected(old('branch_id', $currentBranchId) === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.currency') }}
                    <select class="principal-input" name="currency" required>
                        @foreach ($currencyOptions as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', Currency::IDR) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.method') }}
                    <select class="principal-input" name="method" required>
                        @foreach ($methodOptions as $method => $label)
                            <option value="{{ $method }}" @selected(old('method', 'cash') === $method)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if ($providerOptions !== [])
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.provider') }}
                    <select class="principal-input" name="provider">
                        <option value="">{{ __('lba.commerce.pos.provider_none') }}</option>
                        @foreach ($providerOptions as $code => $label)
                            <option value="{{ $code }}" @selected(old('provider') === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.patient') }}
                    <select class="principal-input" name="patient_id">
                        <option value="">{{ __('lba.commerce.pos.patient_none') }}</option>
                        @foreach ($patientOptions as $id => $name)
                            <option value="{{ $id }}" @selected(old('patient_id') === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.customer') }}
                    <input class="principal-input" name="customer_name" maxlength="160" value="{{ old('customer_name') }}">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.email') }}
                    <input class="principal-input" type="email" name="customer_email" maxlength="160" value="{{ old('customer_email') }}">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.phone') }}
                    <input class="principal-input" name="customer_phone" maxlength="40" value="{{ old('customer_phone') }}">
                </label>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.discount') }}
                    <input class="principal-input" type="number" min="0" step="any" name="discount_total" value="{{ old('discount_total', '0') }}">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.commerce.pos.tax') }}
                    <input class="principal-input" type="number" min="0" step="any" name="tax_total" value="{{ old('tax_total', '0') }}">
                </label>
            </div>

            <label class="grid gap-1 text-sm">
                {{ __('lba.exams.notes') }}
                <textarea class="principal-input" name="notes" rows="2" maxlength="500">{{ old('notes') }}</textarea>
            </label>

            <button class="principal-button principal-button-primary justify-self-start" @disabled($products->isEmpty() || $branchOptions === [])>
                {{ __('lba.commerce.pos.checkout') }}
            </button>
        </section>
    </form>
@endsection
