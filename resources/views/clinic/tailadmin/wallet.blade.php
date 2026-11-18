@extends('principal.layouts.app', ['title' => __('lba.wallet.title'), 'panel' => 'clinic'])

@section('content')
    <p class="mb-5 text-sm text-slate-600">{{ __('lba.wallet.subtitle') }}</p>

    <div class="grid gap-4">
        <section class="principal-card grid gap-4 p-5 sm:grid-cols-3">
            <div>
                <p class="text-sm text-slate-500">{{ __('lba.wallet.available') }}</p>
                <p class="text-2xl font-semibold text-navy-900">{{ number_format($available) }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-500">{{ __('lba.wallet.reserved') }}</p>
                <p class="text-2xl font-semibold text-navy-900">{{ number_format($reserved) }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-500">{{ __('lba.wallet.consumed') }}</p>
                <p class="text-2xl font-semibold text-navy-900">{{ number_format($consumed) }}</p>
            </div>
            @if ($tenantId)
                <p class="text-xs text-slate-500 sm:col-span-3">{{ __('lba.wallet.tenant') }}: <span class="wrap-break-word">{{ $tenantId }}</span></p>
            @endif
        </section>

        @unless ($billingEnabled)
            <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">{{ __('lba.wallet.billing_disabled') }}</p>
        @endunless

        @if ($insufficient && $billingEnabled)
            <p role="alert" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('lba.wallet.insufficient') }}</p>
        @endif

        <section class="principal-card overflow-x-auto">
            <h2 class="border-b border-slate-200 p-4 font-semibold text-navy-900">{{ __('lba.wallet.usage_title') }}</h2>
            @if ($usage->isEmpty())
                <p class="p-8 text-center text-sm text-slate-500">{{ __('lba.wallet.usage_empty') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="p-4">{{ __('lba.wallet.col_time') }}</th>
                            <th class="p-4">{{ __('lba.wallet.col_job') }}</th>
                            <th class="p-4">{{ __('lba.wallet.col_model') }}</th>
                            <th class="p-4">{{ __('lba.wallet.col_units') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($usage as $record)
                            <tr>
                                <td class="p-4 text-slate-600">{{ $record->recorded_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="wrap-break-word p-4">{{ $record->job_reference }}</td>
                                <td class="p-4">{{ $record->provider }}{{ $record->model_name ? ' · '.$record->model_name : '' }}{{ $record->model_version ? ' ('.$record->model_version.')' : '' }}</td>
                                <td class="p-4">{{ number_format((int) $record->credit_units) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="principal-card overflow-x-auto">
            <h2 class="border-b border-slate-200 p-4 font-semibold text-navy-900">{{ __('lba.wallet.ledger_title') }}</h2>
            @if ($ledger->isEmpty())
                <p class="p-8 text-center text-sm text-slate-500">{{ __('lba.wallet.ledger_empty') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="p-4">{{ __('lba.wallet.col_time') }}</th>
                            <th class="p-4">{{ __('lba.wallet.col_action') }}</th>
                            <th class="p-4">{{ __('lba.wallet.col_job') }}</th>
                            <th class="p-4">{{ __('lba.wallet.col_units') }}</th>
                            <th class="p-4">{{ __('lba.wallet.col_balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($ledger as $entry)
                            <tr>
                                <td class="p-4 text-slate-600">{{ $entry->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="p-4">{{ $entry->action }}</td>
                                <td class="wrap-break-word p-4">{{ $entry->job_reference ?? '—' }}</td>
                                <td class="p-4">{{ number_format((int) $entry->credit_units) }}</td>
                                <td class="p-4">{{ number_format((int) $entry->available_after) }} / {{ number_format((int) $entry->reserved_after) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
@endsection
