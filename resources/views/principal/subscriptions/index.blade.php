@extends('principal.layouts.app', ['title' => __('lba.subscriptions.title')])

@section('content')
    <section class="principal-card">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-4">
            <form method="get" class="flex min-w-0 flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm">
                    {{ __('lba.subscriptions.search') }}
                    <input class="principal-input" type="search" name="search" value="{{ $search }}" maxlength="255">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.subscriptions.filter_status') }}
                    <select class="principal-input" name="status">
                        <option value="">{{ __('principal.brands.all_statuses') }}</option>
                        @foreach (['active', 'archived'] as $option)
                            <option value="{{ $option }}" @selected($status === $option)>{{ __('lba.subscriptions.status_'.$option) }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="principal-button">{{ __('principal.brands.apply') }}</button>
            </form>
            @if ($canManage)
                <a class="principal-button principal-button-primary" href="{{ route('principal.tailadmin.subscriptions.create') }}">{{ __('lba.subscriptions.create') }}</a>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach (['name', 'code', 'status', 'price', 'billing_interval', 'trial_days', 'tenants'] as $column)
                            <th scope="col" class="px-4 py-3 text-start">{{ __('lba.subscriptions.'.$column) }}</th>
                        @endforeach
                        <th scope="col" class="px-4 py-3 text-start">{{ __('principal.brands.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($plans as $plan)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $plan->name }}</td>
                            <td class="px-4 py-3">{{ $plan->code }}</td>
                            <td class="px-4 py-3">{{ __('lba.subscriptions.status_'.$plan->status) }}</td>
                            <td class="px-4 py-3">{{ \Modules\Commerce\Support\Currency::format($plan->price_amount, $plan->currency) }}</td>
                            <td class="px-4 py-3">{{ __('lba.subscriptions.interval_'.$plan->billing_interval) }}</td>
                            <td class="px-4 py-3">{{ $plan->trial_days }}</td>
                            <td class="px-4 py-3">{{ $plan->subscriptions_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <a class="principal-button" href="{{ route('principal.tailadmin.subscriptions.edit', $plan) }}">{{ __('lba.subscriptions.edit') }}</a>
                                    @if ($canManage && $plan->status === \App\Platform\Subscriptions\Models\SubscriptionPlan::STATUS_ACTIVE)
                                        <form method="post" action="{{ route('principal.tailadmin.subscriptions.archive', $plan) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button class="principal-button">{{ __('lba.subscriptions.archive') }}</button>
                                        </form>
                                    @elseif ($canManage)
                                        <form method="post" action="{{ route('principal.tailadmin.subscriptions.reactivate', $plan) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button class="principal-button">{{ __('lba.subscriptions.reactivate') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-8 text-center text-slate-500">{{ __('lba.subscriptions.empty_heading') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
