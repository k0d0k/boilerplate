@extends('principal.layouts.app', ['title' => __('principal.brands.title')])

@section('content')
    @include('principal.partials.brand-mitra-tabs', ['active' => 'brand'])
    @php($columns = App\Platform\Crud\Generated\BrandCrud::OPTIONS['columns'])
    <section class="principal-card">
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 p-4 sm:p-5">
            <form method="get" action="{{ route('principal.tailadmin.brands.index') }}" class="flex min-w-0 flex-wrap items-end gap-3">
                <label class="grid flex-1 gap-1.5 text-sm font-medium">
                    {{ __('principal.brands.search') }}
                    <input class="principal-input" type="search" name="search" value="{{ $search }}" maxlength="255">
                </label>
                @if ($filterEnabled)
                    <label class="grid gap-1.5 text-sm font-medium">
                        {{ __('lba.brands.filter_status') }}
                        <select class="principal-input" name="status">
                            <option value="">{{ __('principal.brands.all_statuses') }}</option>
                            @foreach ([App\Models\Brand::STATUS_ACTIVE, App\Models\Brand::STATUS_SUSPENDED] as $option)
                                <option value="{{ $option }}" @selected($status === $option)>{{ __('lba.brands.status_'.$option) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <button class="principal-button" type="submit">{{ __('principal.brands.apply') }}</button>
            </form>
            <div class="flex flex-wrap gap-2">
                @if (Route::has('principal.tailadmin.brands.create'))
                    <a class="principal-button principal-button-primary" href="{{ route('principal.tailadmin.brands.create') }}">{{ __('principal.brands.create') }}</a>
                @endif
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-max text-start text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.brands.'.$column) }}</th>
                        @endforeach
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('principal.brands.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($brands as $brand)
                        <tr>
                            @foreach ($columns as $column)
                                <td @class(['max-w-64 wrap-break-word px-4 py-3 sm:px-5', 'font-semibold text-navy-900' => $column === 'name'])>
                                    @if ($column === 'status')
                                        <x-principal.brand-status :status="$brand->status" />
                                    @else
                                        {{ $brand->{$column} }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-4 py-3 sm:px-5">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if (Route::has('principal.tailadmin.brands.edit'))
                                        <a class="principal-button" href="{{ route('principal.tailadmin.brands.edit', $brand) }}">{{ __('principal.brands.edit') }}</a>
                                    @endif
                                    <a class="principal-button" href="{{ route('principal.tailadmin.brands.report-identity', $brand) }}">{{ __('lba.brands.section_report') }}</a>
                                    <a class="principal-button" href="{{ route('principal.tailadmin.brands.tenants.index', $brand) }}">{{ __('lba.brands.tenants') }}</a>
                                    @if (Route::has('principal.tailadmin.brands.status'))
                                        <form method="post" action="{{ route('principal.tailadmin.brands.status', $brand) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="{{ $brand->isActive() ? App\Models\Brand::STATUS_SUSPENDED : App\Models\Brand::STATUS_ACTIVE }}">
                                            <button type="submit" class="principal-button">{{ __($brand->isActive() ? 'lba.brands.suspend' : 'lba.brands.activate') }}</button>
                                        </form>
                                    @endif
                                    {{-- "Hapus Brand" — Principal Super Admin only. The button
                                         is hidden for anyone else; the DELETE route re-checks
                                         the BrandPolicy::delete() ability server side. --}}
                                    @can('delete', $brand)
                                        @if (Route::has('principal.tailadmin.brands.destroy'))
                                            {{-- Native <details> disclosure (no JavaScript): the
                                                 confirmation must still work on a host where the
                                                 built JS bundle is missing or stale, otherwise the
                                                 button silently does nothing. --}}
                                            <details class="relative">
                                                <summary class="principal-button list-none cursor-pointer">{{ __('principal.delete.action') }}</summary>
                                                <div class="mt-2 min-w-64 rounded-lg border border-slate-200 bg-white p-3 shadow-lg">
                                                    <p class="text-sm text-slate-600">{{ __('principal.delete.brand_confirm_impact', ['name' => $brand->name]) }}</p>
                                                    <form method="post" action="{{ route('principal.tailadmin.brands.destroy', $brand) }}" class="mt-3">
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
                        <tr><td colspan="{{ count($columns) + 1 }}" class="px-5 py-12 text-center text-slate-500">{{ __('principal.brands.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($brands->hasPages())
            <div class="border-t border-slate-200 p-4 sm:p-5">{{ $brands->links() }}</div>
        @endif
    </section>
@endsection
