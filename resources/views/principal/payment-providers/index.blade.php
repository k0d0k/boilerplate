@extends('principal.layouts.app', ['title' => __('lba.payments.plural')])

@section('content')
    <div class="grid gap-5">
        <section class="principal-card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                <p class="max-w-2xl text-xs text-slate-500">{{ __('lba.payments.credentials_notice') }}</p>
                <a class="principal-button principal-button-primary" href="{{ route('principal.tailadmin.payment-providers.create') }}">{{ __('lba.payments.create') }}</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            @foreach (['label', 'provider', 'mode', 'enabled', 'configured', 'verified', 'currencies'] as $column)
                                <th scope="col" class="px-4 py-3 text-start">{{ __('lba.payments.'.$column) }}</th>
                            @endforeach
                            <th scope="col" class="px-4 py-3 text-start">{{ __('principal.brands.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($gateways as $gateway)
                            @php($isConfigured = $configured[$gateway->getKey()] ?? false)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $gateway->label }}</td>
                                <td class="px-4 py-3">{{ $gateway->provider }}</td>
                                <td class="px-4 py-3">{{ __('lba.payments.mode_'.$gateway->mode) }}</td>
                                <td class="px-4 py-3">{{ __($gateway->enabled ? 'lba.modules.yes' : 'lba.modules.no') }}</td>
                                <td class="px-4 py-3">{{ __($isConfigured ? 'lba.payments.configured_yes' : 'lba.payments.configured_no') }}</td>
                                <td class="px-4 py-3">{{ __($gateway->isVerified() ? 'lba.payments.verified_yes' : 'lba.payments.verified_no') }}</td>
                                <td class="px-4 py-3">{{ $gateway->currencies === null || $gateway->currencies === [] ? '—' : implode(', ', $gateway->currencies) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <a class="principal-button" href="{{ route('principal.tailadmin.payment-providers.edit', $gateway) }}">{{ __('lba.payments.edit') }}</a>
                                        @if ($gateway->isVerified())
                                            <form method="post" action="{{ route('principal.tailadmin.payment-providers.unverify', $gateway) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="principal-button">{{ __('lba.payments.unverify') }}</button>
                                            </form>
                                        @else
                                            <form method="post" action="{{ route('principal.tailadmin.payment-providers.verify', $gateway) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="principal-button">{{ __('lba.payments.verify') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="p-8 text-center text-slate-500">{{ __('lba.payments.empty_heading') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
