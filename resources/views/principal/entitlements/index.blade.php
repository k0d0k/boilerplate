@extends('principal.layouts.app', ['title' => __('lba.feature_access.title')])

@section('content')
    <div class="grid gap-5">
        <section class="principal-card grid gap-4 p-5">
            <p class="text-xs text-slate-500">{{ __('lba.feature_access.notice') }}</p>
            <form method="get" class="flex flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm">
                    {{ __('lba.feature_access.choose_tenant') }}
                    <select class="principal-input" name="tenant" onchange="this.form.submit()">
                        <option value="">{{ __('lba.feature_access.select_placeholder') }}</option>
                        @foreach ($tenants as $id => $name)
                            <option value="{{ $id }}" @selected((string) $tenant?->getKey() === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="principal-button">{{ __('principal.brands.apply') }}</button>
            </form>
        </section>

        @if (! $tenant instanceof \App\Models\Tenant)
            <section class="principal-card p-5">
                <p class="text-sm text-slate-500">{{ __('lba.feature_access.no_tenant') }}</p>
            </section>
        @else
            <section class="principal-card">
                <h2 class="border-b border-slate-200 p-4 font-semibold text-navy-900">{{ __('lba.feature_access.entitlements') }}</h2>
                @if ($entitlements === [])
                    <p class="p-4 text-sm text-slate-500">{{ __('lba.feature_access.no_modules') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-start">{{ __('lba.modules.col_module') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start">{{ __('lba.feature_access.col_entitled') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start">{{ __('principal.brands.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($entitlements as $row)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <strong>{{ $row['name'] }}</strong><br>
                                            <span class="text-xs text-slate-500">{{ $row['key'] }}</span>
                                        </td>
                                        <td class="px-4 py-3">{{ __($row['granted'] ? 'lba.modules.yes' : 'lba.modules.no') }}</td>
                                        <td class="px-4 py-3">
                                            <form method="post" action="{{ route('principal.tailadmin.entitlements.'.($row['granted'] ? 'revoke' : 'grant'), $tenant) }}">
                                                @csrf
                                                <input type="hidden" name="module_key" value="{{ $row['key'] }}">
                                                <button class="principal-button">{{ __($row['granted'] ? 'lba.feature_access.revoke' : 'lba.feature_access.grant') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="principal-card">
                <h2 class="border-b border-slate-200 p-4 font-semibold text-navy-900">{{ __('lba.feature_access.members') }}</h2>
                @if ($memberships->isEmpty())
                    <p class="p-4 text-sm text-slate-500">{{ __('lba.feature_access.no_members') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-start">{{ __('lba.tenants.member') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start">{{ __('lba.tenants.roles') }}</th>
                                    <th scope="col" class="px-4 py-3 text-start">{{ __('principal.brands.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($memberships as $membership)
                                    @php($current = $membership->roles->pluck('key')->all())
                                    <tr>
                                        <td class="px-4 py-3">
                                            {{ $membership->user?->name ?? '—' }}<br>
                                            <span class="text-xs text-slate-500">{{ $membership->user?->email }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="grid gap-1">
                                                @foreach ($assignableRoles as $roleKey)
                                                    <label class="flex items-center gap-2">
                                                        <input type="checkbox" form="member-roles-{{ $membership->getKey() }}" name="role_keys[]" value="{{ $roleKey }}" @checked(in_array($roleKey, $current, true))>
                                                        {{ __("lba.roles.{$roleKey}") }}
                                                    </label>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <form id="member-roles-{{ $membership->getKey() }}" method="post" action="{{ route('principal.tailadmin.entitlements.roles', [$tenant, $membership]) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="principal-button">{{ __('lba.feature_access.save_roles') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="principal-card">
                <h2 class="border-b border-slate-200 p-4 font-semibold text-navy-900">{{ __('lba.feature_access.matrix') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-start">{{ __('lba.feature_access.col_role') }}</th>
                                <th scope="col" class="px-4 py-3 text-start">{{ __('lba.feature_access.col_permissions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($roleMatrix as $roleKey => $permissions)
                                <tr>
                                    <td class="px-4 py-3 font-semibold">{{ __("lba.roles.{$roleKey}") }}</td>
                                    <td class="px-4 py-3">
                                        @forelse ($permissions as $permission)
                                            <span class="mr-1 mb-1 inline-block rounded bg-slate-100 px-2 py-0.5 text-xs">{{ __("lba.permissions.{$permission}") }}</span>
                                        @empty
                                            <span class="text-slate-500">—</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
