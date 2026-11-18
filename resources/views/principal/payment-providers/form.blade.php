@extends('principal.layouts.app', ['title' => __($gateway->exists ? 'lba.payments.edit' : 'lba.payments.create')])

@section('content')
    <div class="grid max-w-3xl gap-5">
        <section class="principal-card p-5">
            <form method="post" data-payment-form data-provider-current="{{ $gateway->provider }}"
                action="{{ $gateway->exists ? route('principal.tailadmin.payment-providers.update', $gateway) : route('principal.tailadmin.payment-providers.store') }}" class="grid gap-4">
                @csrf
                @if ($gateway->exists)
                    @method('PATCH')
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.payments.provider') }}
                        @if ($gateway->exists)
                            <input class="principal-input" value="{{ $gateway->provider }}" disabled>
                        @else
                            <select class="principal-input" name="provider" required data-provider-select>
                                @foreach ($providerOptions as $key => $label)
                                    <option value="{{ $key }}" @selected(old('provider') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        @endif
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.payments.label') }}
                        <input class="principal-input" name="label" maxlength="255" required value="{{ old('label', $gateway->label) }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.payments.mode') }}
                        <select class="principal-input" name="mode" required>
                            @foreach (['sandbox', 'live'] as $mode)
                                <option value="{{ $mode }}" @selected(old('mode', $gateway->mode) === $mode)>{{ __('lba.payments.mode_'.$mode) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 self-end text-sm">
                        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $gateway->enabled))>
                        {{ __('lba.payments.enabled') }}
                    </label>
                </div>
                <p class="text-xs text-slate-500">{{ __('lba.payments.provider_help') }}</p>

                <fieldset class="grid gap-4 border-t border-slate-200 pt-4">
                    <legend class="text-sm font-medium">{{ __('lba.payments.section_credentials') }}</legend>
                    <p class="text-xs text-slate-500">{{ __('lba.payments.credentials_notice') }}</p>

                    @php($fields = [
                        'midtrans' => ['server_key', 'client_key'],
                        'ipaymu' => ['api_key', 'va'],
                        'paypal' => ['client_id', 'client_secret'],
                    ])
                    @foreach ($fields as $provider => $keys)
                        @foreach ($keys as $key)
                            <label class="grid gap-1 text-sm" data-provider-block="{{ $provider }}">
                                {{ __('lba.payments.credential_'.$key) }}
                                <input class="principal-input" type="{{ in_array($key, ['server_key', 'client_key', 'client_secret'], true) ? 'password' : 'text' }}"
                                    name="credential_{{ $key }}" maxlength="255" autocomplete="new-password"
                                    placeholder="{{ $gateway->exists ? '••••••••' : '' }}">
                            </label>
                        @endforeach
                    @endforeach
                    <label class="grid gap-1 text-sm" data-provider-block="paypal">
                        {{ __('lba.payments.config_webhook_id') }}
                        <input class="principal-input" name="config_webhook_id" maxlength="255" value="{{ old('config_webhook_id', $gateway->setting('webhook_id')) }}">
                    </label>
                </fieldset>

                <fieldset class="grid gap-2 border-t border-slate-200 pt-4">
                    <legend class="text-sm font-medium">{{ __('lba.payments.currencies') }}</legend>
                    <p class="text-xs text-slate-500">{{ __('lba.payments.currencies_help') }}</p>
                    @php($selectedCurrencies = (array) old('currencies', $gateway->currencies ?? []))
                    <div class="flex flex-wrap gap-4">
                        @foreach (\Modules\Commerce\Support\Currency::SUPPORTED as $code)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="currencies[]" value="{{ $code }}" @checked(in_array($code, $selectedCurrencies, true))>
                                {{ $code }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="flex flex-wrap gap-2">
                    <button class="principal-button principal-button-primary">{{ __('principal.brands.save') }}</button>
                    <a class="principal-button" href="{{ route('principal.tailadmin.payment-providers.index') }}">{{ __('principal.brands.cancel') }}</a>
                </div>
            </form>
        </section>
    </div>

    <script>
        (function () {
            var form = document.querySelector('[data-payment-form]');
            var select = document.querySelector('[data-provider-select]');
            var blocks = document.querySelectorAll('[data-provider-block]');
            if (!blocks.length) return;
            var current = function () {
                if (select) return select.value;
                return form ? form.getAttribute('data-provider-current') : '';
            };
            var sync = function () {
                var provider = current();
                blocks.forEach(function (block) {
                    block.hidden = block.getAttribute('data-provider-block') !== provider;
                });
            };
            if (select) select.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
