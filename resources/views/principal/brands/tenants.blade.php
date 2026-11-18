@extends('principal.layouts.app', ['title' => __('lba.brands.tenants')])

@section('content')
    <section class="principal-card">
        <div class="grid gap-4 border-b border-slate-200 p-4 sm:p-5">
            <h2 class="wrap-break-word text-lg font-semibold text-navy-900">{{ $brand->name }}</h2>
            @if ($availableTenants->isNotEmpty())
                <form method="post" action="{{ route('principal.tailadmin.brands.tenants.attach', $brand) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="grid min-w-0 flex-1 gap-1.5 text-sm font-medium">
                        {{ __('lba.brands.tenants') }}
                        <select name="tenant_id" class="principal-input" required>
                            @foreach ($availableTenants as $tenant)
                                <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex min-h-10 items-center gap-2 text-sm">
                        <input type="checkbox" name="is_primary" value="1"> {{ __('lba.brands.is_primary') }}
                    </label>
                    <button class="principal-button principal-button-primary" type="submit">{{ __('lba.brands.attach_tenant') }}</button>
                </form>
            @endif
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse ($tenants as $tenant)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
                    <div class="min-w-0">
                        <div class="wrap-break-word font-semibold text-navy-900">{{ $tenant->name }}</div>
                        <div class="text-sm text-slate-600">{{ __('lba.tenants.status_'.$tenant->status) }}
                            @if ($tenant->pivot->is_primary) · {{ __('lba.brands.is_primary') }} @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if (! $tenant->pivot->is_primary)
                            <form method="post" action="{{ route('principal.tailadmin.brands.tenants.primary', [$brand, $tenant]) }}">
                                @csrf
                                @method('PATCH')
                                <button class="principal-button" type="submit">{{ __('lba.brands.make_primary') }}</button>
                            </form>
                        @endif
                        @if ($canOpen && $tenant->status === App\Models\Tenant::STATUS_ACTIVE)
                            <form method="post" action="{{ route('principal.tailadmin.brands.tenants.open', [$brand, $tenant]) }}">
                                @csrf
                                <button class="principal-button" type="submit">{{ __('lba.support.open') }}</button>
                            </form>
                        @endif
                        <form method="post" action="{{ route('principal.tailadmin.brands.tenants.detach', [$brand, $tenant]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="principal-button" type="submit">{{ __('lba.brands.detach_tenant') }}</button>
                        </form>
                    </div>
                </li>
            @empty
                <li class="p-5 text-sm text-slate-600">{{ __('lba.brands.empty_tenants') }}</li>
            @endforelse
        </ul>
        @if ($tenants->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $tenants->links() }}</div>
        @endif
    </section>
@endsection
