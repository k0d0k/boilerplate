@extends('principal.layouts.app', ['title' => __($plan->exists ? 'lba.subscriptions.edit' : 'lba.subscriptions.create')])

@section('content')
    <div class="grid max-w-3xl gap-5">
        <section class="principal-card p-5">
            <form method="post" action="{{ $plan->exists ? route('principal.tailadmin.subscriptions.update', $plan) : route('principal.tailadmin.subscriptions.store') }}" class="grid gap-4">
                @csrf
                @if ($plan->exists)
                    @method('PATCH')
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.subscriptions.code') }}
                        <input class="principal-input" name="code" maxlength="255" required value="{{ old('code', $plan->code) }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.subscriptions.name') }}
                        <input class="principal-input" name="name" maxlength="255" required value="{{ old('name', $plan->name) }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.subscriptions.status') }}
                        <select name="status" class="principal-input" required>
                            @foreach (['active', 'archived'] as $option)
                                <option value="{{ $option }}" @selected(old('status', $plan->status) === $option)>{{ __('lba.subscriptions.status_'.$option) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.subscriptions.billing_interval') }}
                        <select name="billing_interval" class="principal-input" required>
                            @foreach (['monthly', 'yearly'] as $option)
                                <option value="{{ $option }}" @selected(old('billing_interval', $plan->billing_interval) === $option)>{{ __('lba.subscriptions.interval_'.$option) }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label class="grid gap-1 text-sm">
                    {{ __('lba.subscriptions.description') }}
                    <textarea class="principal-input" name="description" maxlength="255" rows="2">{{ old('description', $plan->description) }}</textarea>
                </label>

                <div class="grid gap-4 sm:grid-cols-4">
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.subscriptions.price_amount') }}
                        <input class="principal-input" type="number" step="0.01" min="0" name="price_amount" required value="{{ old('price_amount', $plan->price_amount) }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.subscriptions.currency') }}
                        <select name="currency" class="principal-input" required>
                            @foreach (\Modules\Commerce\Support\Currency::SUPPORTED as $code)
                                <option value="{{ $code }}" @selected(old('currency', $plan->currency) === $code)>{{ $code }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.subscriptions.trial_days') }}
                        <input class="principal-input" type="number" min="0" name="trial_days" required value="{{ old('trial_days', $plan->trial_days ?? 0) }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.subscriptions.seat_limit') }}
                        <input class="principal-input" type="number" min="1" name="seat_limit" value="{{ old('seat_limit', $plan->seat_limit) }}">
                    </label>
                </div>
                <p class="text-xs text-slate-500">{{ __('lba.subscriptions.seat_limit_help') }}</p>

                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium">{{ __('lba.subscriptions.module_keys') }}</legend>
                    <p class="text-xs text-slate-500">{{ __('lba.subscriptions.section_modules_notice') }}</p>
                    @php($selectedModules = (array) old('module_keys', $plan->moduleKeys()))
                    <div class="grid gap-2 sm:grid-cols-2">
                        @forelse ($moduleOptions as $key => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="module_keys[]" value="{{ $key }}" @checked(in_array($key, $selectedModules, true))>
                                {{ $label }}
                            </label>
                        @empty
                            <p class="text-sm text-slate-500">{{ __('lba.feature_access.no_modules') }}</p>
                        @endforelse
                    </div>
                </fieldset>

                <div class="flex flex-wrap gap-2">
                    <button class="principal-button principal-button-primary">{{ __('principal.brands.save') }}</button>
                    <a class="principal-button" href="{{ route('principal.tailadmin.subscriptions.index') }}">{{ __('principal.brands.cancel') }}</a>
                </div>
            </form>
        </section>
    </div>
@endsection
