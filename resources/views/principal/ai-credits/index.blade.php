@extends('principal.layouts.app', ['title' => __('lba.nav.principal.ai_usage')])

@section('content')
    <div class="grid gap-5">
        @if ($canGrant)
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('lba.wallets.grant') }}</h2>
                <p class="text-xs text-slate-500">{{ __('lba.wallets.grant_description') }}</p>
                @if ($wallets->isEmpty())
                    <p class="text-sm text-slate-500">{{ __('lba.wallets.empty_description') }}</p>
                @else
                    <form method="post" action="" class="grid gap-4" data-wallet-grant>
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.wallets.tenant') }}
                                <select class="principal-input" name="wallet_id" required data-wallet-select>
                                    @foreach ($wallets as $wallet)
                                        <option value="{{ route('principal.tailadmin.ai-credits.grant', $wallet) }}">{{ $wallet->tenant?->name ?? $wallet->tenant_id }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.wallets.units') }}
                                <input class="principal-input" type="number" min="1" name="units" required>
                            </label>
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.wallets.reason') }}
                                <input class="principal-input" name="reason" maxlength="255" required>
                            </label>
                        </div>
                        <p class="text-xs text-slate-500">{{ __('lba.wallets.reason_help') }}</p>
                        <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.wallets.grant') }}</button>
                    </form>
                @endif
            </section>
        @endif

        <section class="principal-card">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            @foreach (['tenant', 'available', 'reserved', 'consumed', 'total', 'status'] as $column)
                                <th scope="col" class="px-4 py-3 text-start">{{ __('lba.wallets.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($wallets as $wallet)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $wallet->tenant?->name ?? $wallet->tenant_id }}</td>
                                <td class="px-4 py-3">{{ $wallet->available_credits }}</td>
                                <td class="px-4 py-3">{{ $wallet->reserved_credits }}</td>
                                <td class="px-4 py-3">{{ $wallet->lifetime_consumed_credits }}</td>
                                <td class="px-4 py-3">{{ $wallet->totalCredits() }}</td>
                                <td class="px-4 py-3">{{ __('lba.wallets.status_'.$wallet->status) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-8 text-center text-slate-500">{{ __('lba.wallets.empty_heading') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @if ($canGrant && $wallets->isNotEmpty())
        <script>
            (function () {
                var form = document.querySelector('[data-wallet-grant]');
                var select = document.querySelector('[data-wallet-select]');
                if (!form || !select) return;
                var sync = function () { form.setAttribute('action', select.value); };
                select.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endif
@endsection
