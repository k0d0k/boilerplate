@extends('principal.layouts.app', ['title' => __('principal.tenants.title')])

@section('content')
    @include('principal.partials.brand-mitra-tabs', ['active' => 'mitra'])
    <section class="principal-card">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-4">
            <form method="get" class="flex min-w-0 flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm">
                    {{ __('principal.tenants.search') }}
                    <input class="principal-input" type="search" name="search" value="{{ $search }}" maxlength="255">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.tenants.filter_status') }}
                    <select class="principal-input" name="status">
                        <option value="">{{ __('principal.brands.all_statuses') }}</option>
                        @foreach (App\Http\Requests\Principal\SaveTenantRequest::STATUSES as $option)
                            <option value="{{ $option }}" @selected($status === $option)>{{ __('lba.tenants.status_'.$option) }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="principal-button">{{ __('principal.brands.apply') }}</button>
            </form>
            <a class="principal-button principal-button-primary" href="{{ route('principal.tailadmin.tenants.create') }}">{{ __('principal.tenants.create') }}</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach (['name', 'slug', 'status', 'db_status', 'brands'] as $column)
                            <th scope="col" class="px-4 py-3 text-start">{{ __('lba.tenants.'.$column) }}</th>
                        @endforeach
                        <th scope="col" class="px-4 py-3 text-start">{{ __('principal.brands.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($tenants as $tenant)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $tenant->name }}</td>
                            <td class="px-4 py-3">{{ $tenant->slug }}</td>
                            <td class="px-4 py-3">{{ __('lba.tenants.status_'.$tenant->status) }}</td>
                            <td class="px-4 py-3">{{ __('lba.tenants.db_status_'.($tenant->database?->status ?? 'pending')) }}</td>
                            <td class="px-4 py-3">{{ $tenant->brands_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <a class="principal-button" href="{{ route('principal.tailadmin.tenants.edit', $tenant) }}">{{ __('principal.tenants.edit') }}</a>
                                    @if ($tenant->isActive())
                                        <form method="post" action="{{ route('principal.tailadmin.tenants.open', $tenant) }}">
                                            @csrf
                                            <button class="principal-button">{{ __('lba.support.open') }}</button>
                                        </form>
                                    @endif
                                    {{-- "Hapus Mitra" — Principal Super Admin only. The button is
                                         hidden for anyone else; the DELETE route re-checks the
                                         TenantPolicy::delete() ability server side. Removal is a
                                         soft delete: access stops, but the clinical database, the
                                         cPanel schema and the claimed database name are preserved. --}}
                                    @can('delete', $tenant)
                                        @if (Route::has('principal.tailadmin.tenants.destroy'))
                                            <details>
                                                <summary class="principal-button list-none cursor-pointer">{{ __('principal.delete.action') }}</summary>
                                                <div class="mt-2 min-w-64 rounded-lg border border-slate-200 bg-white p-3 shadow-lg">
                                                    <p class="text-sm text-slate-600">{{ __('principal.delete.tenant_confirm_impact', ['name' => $tenant->name]) }}</p>
                                                    <form method="post" action="{{ route('principal.tailadmin.tenants.destroy', $tenant) }}" class="mt-3">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="principal-button principal-button-primary">{{ __('principal.delete.confirm') }}</button>
                                                    </form>
                                                </div>
                                            </details>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-slate-500">{{ __('lba.tenants.empty_heading') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($tenants->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $tenants->links() }}</div>
        @endif
    </section>
@endsection
