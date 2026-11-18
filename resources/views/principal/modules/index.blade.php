@extends('principal.layouts.app', ['title' => __('lba.module_packages.title')])

@section('content')
    <div class="mb-5 grid gap-1">
        <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.module_packages.eyebrow') }}</p>
        <p class="max-w-3xl text-sm text-slate-600">{{ __('lba.module_packages.notice') }}</p>
    </div>

    {{-- Trusted registry: modules DECLARED on disk, independent of any upload. --}}
    <section class="principal-card mb-5">
        <h2 class="border-b border-slate-200 p-4 font-semibold text-navy-900 sm:p-5">{{ __('lba.module_packages.registry_heading') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-max text-start text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_module') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_version') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_compatible') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_dependencies') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_global') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($modules as $module)
                        <tr>
                            <td class="px-4 py-3 font-semibold text-navy-900 sm:px-5">{{ $module['name'] }} <span class="font-normal text-slate-500">({{ $module['key'] }})</span></td>
                            <td class="px-4 py-3 sm:px-5">{{ $module['version'] }}</td>
                            <td class="px-4 py-3 sm:px-5">{{ __($module['compatible'] ? 'lba.module_packages.yes' : 'lba.module_packages.no') }}</td>
                            <td class="px-4 py-3 sm:px-5">{{ $module['dependencies'] === [] ? __('lba.module_packages.none') : implode(', ', $module['dependencies']) }}</td>
                            <td class="px-4 py-3 sm:px-5">{{ __($module['enabled'] ? 'lba.module_packages.enabled' : 'lba.module_packages.disabled') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-12 text-center text-slate-500">{{ __('lba.module_packages.registry_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Upload: stores verbatim in quarantine; never executes package code. --}}
    <section class="principal-card mb-5 grid gap-4 p-5">
        <h2 class="font-semibold text-navy-900">{{ __('lba.module_packages.upload_heading') }}</h2>
        <p class="text-xs text-slate-500">{{ __('lba.module_packages.upload_notice') }}</p>
        <form method="post" action="{{ route('principal.tailadmin.modules.packages.store') }}" enctype="multipart/form-data" class="grid max-w-xl gap-4">
            @csrf
            <label class="grid gap-1 text-sm">
                {{ __('lba.module_packages.file') }}
                <input class="principal-input" type="file" name="package" accept=".zip,application/zip" required>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.module_packages.signature') }}
                <textarea class="principal-input" name="signature" rows="3" maxlength="8192"></textarea>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.module_packages.publisher_optional') }}
                <input class="principal-input" name="publisher" maxlength="64" value="{{ old('publisher') }}">
            </label>
            <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.module_packages.upload') }}</button>
        </form>
    </section>

    {{-- Uploaded packages + per-tenant activation/rollback. --}}
    <section class="principal-card">
        <h2 class="border-b border-slate-200 p-4 font-semibold text-navy-900 sm:p-5">{{ __('lba.module_packages.packages_heading') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-max text-start text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_module') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_version') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_publisher') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_checksum') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_state') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_uploaded_by') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.module_packages.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($packages as $package)
                        <tr>
                            <td class="px-4 py-3 font-semibold text-navy-900 sm:px-5">{{ $package->module_key ?? '—' }}</td>
                            <td class="px-4 py-3 sm:px-5">{{ $package->version ?? '—' }}</td>
                            <td class="px-4 py-3 sm:px-5">{{ $package->publisher ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500 sm:px-5" title="{{ $package->checksum }}">{{ substr($package->checksum, 0, 12) }}</td>
                            <td class="px-4 py-3 sm:px-5">
                                {{ __('lba.module_packages.state_'.$package->state) }}
                                @if ($package->reason)
                                    <span class="block text-xs text-slate-500">{{ __("lba.module_packages.reason_{$package->reason}") }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 sm:px-5">{{ $package->uploader?->name ?? '—' }}</td>
                            <td class="px-4 py-3 sm:px-5">
                                <div class="flex flex-wrap items-end gap-2">
                                    @if ($package->isVerified())
                                        <form method="post" action="{{ route('principal.tailadmin.modules.packages.activate', $package) }}" class="flex flex-wrap items-end gap-2">
                                            @csrf
                                            <label class="grid gap-1 text-xs">
                                                {{ __('lba.module_packages.choose_tenant') }}
                                                <select class="principal-input" name="tenant" required>
                                                    <option value="">{{ __('lba.module_packages.select_placeholder') }}</option>
                                                    @foreach ($tenants as $tenantId => $tenantName)
                                                        <option value="{{ $tenantId }}">{{ $tenantName }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <button class="principal-button principal-button-primary">{{ __('lba.module_packages.activate') }}</button>
                                        </form>
                                    @endif
                                    @if ($package->isActivated())
                                        <form method="post" action="{{ route('principal.tailadmin.modules.packages.rollback', $package) }}">
                                            @csrf
                                            <button class="principal-button">{{ __('lba.module_packages.rollback') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-12 text-center text-slate-500">{{ __('lba.module_packages.packages_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
