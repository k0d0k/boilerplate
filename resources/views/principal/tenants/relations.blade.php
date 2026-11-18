@extends('principal.layouts.app', ['title' => __('principal.tenants.relations_title')])

@section('content')
    @include('principal.partials.brand-mitra-tabs', ['active' => 'mitra'])

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 class="wrap-break-word text-lg font-semibold text-navy-900">{{ $tenant->name }}</h2>
            <p class="text-sm text-slate-600">{{ __('lba.tenants.status_'.$tenant->status) }} · {{ $tenant->slug }}</p>
        </div>
        <a class="principal-button" href="{{ route('principal.tailadmin.tenants.edit', $tenant) }}">{{ __('principal.tenants.back_to_tenant') }}</a>
    </div>

    {{-- ---------------------------------------------------------- Memberships --}}
    <section class="principal-card mb-5">
        <div class="grid gap-4 border-b border-slate-200 p-4 sm:p-5">
            <h3 class="text-base font-semibold text-navy-900">{{ __('lba.tenants.memberships') }}</h3>
            <div class="grid gap-4 lg:grid-cols-2">
                <form method="post" action="{{ route('principal.tailadmin.tenants.members.owner', $tenant) }}" class="grid gap-3">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.tenants.assign_owner') }}
                        <select name="user_id" class="principal-input" required>
                            <option value="">—</option>
                            @foreach ($candidateUsers as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="principal-button principal-button-primary justify-self-start" type="submit">{{ __('lba.tenants.assign_owner') }}</button>
                </form>
                <form method="post" action="{{ route('principal.tailadmin.tenants.members.invite', $tenant) }}" class="grid gap-3">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.tenants.invite_email') }}
                        <input class="principal-input" type="email" name="email" maxlength="255" required>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.tenants.invite_name') }}
                        <input class="principal-input" name="name" maxlength="255" required>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.tenants.invite_roles') }}
                        <select name="role_keys[]" class="principal-input" multiple size="5" required>
                            @foreach ($staffRoleGroups as $groupLabel => $options)
                                <optgroup label="{{ $groupLabel }}">
                                    @foreach ($options as $roleKey => $optionLabel)
                                        <option value="{{ $roleKey }}">{{ $optionLabel }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <button class="principal-button principal-button-primary justify-self-start" type="submit">{{ __('lba.tenants.invite_staff') }}</button>
                </form>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-max text-start text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        @foreach (['member', 'email', 'member_status', 'roles'] as $column)
                            <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.tenants.'.$column) }}</th>
                        @endforeach
                        <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('principal.brands.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($memberships as $membership)
                        <tr>
                            <td class="px-4 py-3 font-semibold text-navy-900 sm:px-5">{{ $membership->user?->name }}</td>
                            <td class="px-4 py-3 sm:px-5">{{ $membership->user?->email }}</td>
                            <td class="px-4 py-3 sm:px-5">{{ __('lba.tenants.member_status_'.$membership->status) }}</td>
                            <td class="px-4 py-3 sm:px-5">
                                <form method="post" action="{{ route('principal.tailadmin.tenants.members.roles', [$tenant, $membership]) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="role_keys[]" class="principal-input" multiple size="3">
                                        @foreach ($assignableRoleGroups as $groupLabel => $options)
                                            <optgroup label="{{ $groupLabel }}">
                                                @foreach ($options as $roleKey => $optionLabel)
                                                    <option value="{{ $roleKey }}" @selected($membership->roles->contains('key', $roleKey))>{{ $optionLabel }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    <button class="principal-button" type="submit">{{ __('lba.tenants.edit_roles') }}</button>
                                </form>
                            </td>
                            <td class="px-4 py-3 sm:px-5">
                                <div class="flex flex-wrap gap-2">
                                    @if ($membership->status !== App\Models\TenantMembership::STATUS_ACTIVE)
                                        <form method="post" action="{{ route('principal.tailadmin.tenants.members.status', [$tenant, $membership]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="{{ App\Models\TenantMembership::STATUS_ACTIVE }}">
                                            <button class="principal-button" type="submit">{{ __('lba.tenants.member_activate') }}</button>
                                        </form>
                                    @endif
                                    @if ($membership->status === App\Models\TenantMembership::STATUS_ACTIVE)
                                        <form method="post" action="{{ route('principal.tailadmin.tenants.members.status', [$tenant, $membership]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="{{ App\Models\TenantMembership::STATUS_SUSPENDED }}">
                                            <button class="principal-button" type="submit">{{ __('lba.tenants.member_suspend') }}</button>
                                        </form>
                                    @endif
                                    @if ($membership->status !== App\Models\TenantMembership::STATUS_REVOKED)
                                        <form method="post" action="{{ route('principal.tailadmin.tenants.members.status', [$tenant, $membership]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="{{ App\Models\TenantMembership::STATUS_REVOKED }}">
                                            <button class="principal-button" type="submit">{{ __('lba.tenants.member_revoke') }}</button>
                                        </form>
                                    @endif
                                    @if ($canManageAccess && $membership->user !== null)
                                        <form method="post" action="{{ route('principal.tailadmin.tenants.members.password-reset', [$tenant, $membership]) }}">
                                            @csrf
                                            <button class="principal-button" type="submit">{{ __('lba.password_reset.super_admin_action') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-5 text-sm text-slate-600">{{ __('lba.tenants.empty_members') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- -------------------------------------------------------- Subscriptions --}}
    @if ($canViewSubscriptions)
        <section class="principal-card mb-5">
            <div class="grid gap-4 border-b border-slate-200 p-4 sm:p-5">
                <h3 class="text-base font-semibold text-navy-900">{{ __('lba.subscriptions.title') }}</h3>
                @if ($canManageBilling)
                    <form method="post" action="{{ route('principal.tailadmin.tenants.subscriptions.store', $tenant) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <label class="grid min-w-0 flex-1 gap-1 text-sm">
                            {{ __('lba.subscriptions.plan') }}
                            <select name="plan_id" class="principal-input" required>
                                @foreach ($activePlans as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.subscriptions.seats') }}
                            <input class="principal-input" type="number" name="seats" min="1" value="1">
                        </label>
                        <button class="principal-button principal-button-primary" type="submit">{{ __('lba.subscriptions.subscribe') }}</button>
                    </form>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-start text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            @foreach (['plan', 'status', 'seats', 'period_end', 'reference'] as $column)
                                <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('lba.subscriptions.'.$column) }}</th>
                            @endforeach
                            <th scope="col" class="px-4 py-3 text-start font-semibold sm:px-5">{{ __('principal.brands.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($subscriptions as $subscription)
                            <tr>
                                <td class="px-4 py-3 font-semibold text-navy-900 sm:px-5">{{ $subscription->plan?->name }}</td>
                                <td class="px-4 py-3 sm:px-5">{{ __('lba.subscriptions.sub_status_'.$subscription->status) }}</td>
                                <td class="px-4 py-3 sm:px-5">{{ $subscription->seats }}</td>
                                <td class="px-4 py-3 sm:px-5">{{ $subscription->current_period_end?->toDateString() ?? '—' }}</td>
                                <td class="px-4 py-3 sm:px-5">{{ $subscription->reference }}</td>
                                <td class="px-4 py-3 sm:px-5">
                                    @if ($canManageBilling)
                                        <div class="flex flex-wrap gap-2">
                                            @if (! $subscription->isActive())
                                                <form method="post" action="{{ route('principal.tailadmin.tenants.subscriptions.activate', [$tenant, $subscription]) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button class="principal-button" type="submit">{{ __('lba.subscriptions.activate') }}</button>
                                                </form>
                                            @endif
                                            @if ($subscription->cancelled_at === null)
                                                <form method="post" action="{{ route('principal.tailadmin.tenants.subscriptions.cancel', [$tenant, $subscription]) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button class="principal-button" type="submit">{{ __('lba.subscriptions.cancel') }}</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-5 text-sm text-slate-600">{{ __('lba.subscriptions.empty_heading') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------- Tenant Database --}}
    @if ($canManageDatabase)
        <section class="principal-card mb-5">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 sm:p-5">
                <h3 class="text-base font-semibold text-navy-900">{{ __('principal.tenants.database.heading') }}</h3>
                <p class="text-sm text-slate-600">{{ __('principal.tenants.database.super_admin_hint') }}</p>
            </div>

            <dl class="grid gap-4 border-b border-slate-200 p-4 sm:p-5 sm:grid-cols-2">
                <div class="grid gap-1">
                    <dt class="text-sm text-slate-500">{{ __('principal.tenants.database.physical_name') }}</dt>
                    <dd class="flex flex-wrap items-center gap-2">
                        <code data-database-name class="break-all rounded bg-slate-100 px-2 py-1 text-sm text-navy-900">{{ $database['target'] !== '' ? $database['target'] : __('principal.tenants.database.not_available') }}</code>
                        @if ($database['target'] !== '')
                            <button type="button" class="principal-button" data-copy-database data-copied-label="{{ __('principal.tenants.database.copied') }}">{{ __('principal.tenants.database.copy') }}</button>
                        @endif
                    </dd>
                    <p class="text-xs text-slate-500">{{ __('principal.tenants.database.physical_hint') }}</p>
                </div>
                <div class="grid gap-3">
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('principal.tenants.database.mode') }}</dt>
                        <dd class="font-medium text-navy-900">{{ __('principal.tenants.database.mode_'.$database['mode']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('principal.tenants.database.status') }}</dt>
                        <dd class="font-medium text-navy-900">{{ __('principal.tenants.database.state_'.($database['state']['state'] ?? 'pending')) }}</dd>
                    </div>
                </div>
            </dl>

            {{-- Manual MySQL target + credential. The NAME is validated by the
                 same configurator as onboarding (central database / claimed name
                 / mismatch all fail closed). The password is WRITE-ONLY: never
                 pre-filled, never echoed back; a blank submission keeps the
                 stored (encrypted) value. --}}
            <form method="post" action="{{ route('principal.tailadmin.tenants.database.credentials', $tenant) }}" class="grid gap-3 border-b border-slate-200 p-4 sm:p-5">
                @csrf
                <h4 class="text-sm font-semibold text-navy-900">{{ __('principal.tenants.database.credentials_heading') }}</h4>
                <label class="grid gap-1 text-sm">
                    {{ __('principal.tenants.db_name') }}
                    <input class="principal-input" name="db_name" maxlength="64" value="{{ old('db_name', $database['configured_name']) }}">
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.tenants.database.username') }}
                        <input class="principal-input" name="db_username" maxlength="64" value="{{ old('db_username', $database['username']) }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.tenants.database.password') }}
                        <input class="principal-input" type="password" name="db_password" maxlength="255" value="" autocomplete="new-password"
                            placeholder="{{ $database['password_configured'] ? '••••••••' : '' }}">
                    </label>
                </div>
                <p class="text-xs text-slate-500">
                    {{ $database['password_configured'] ? __('principal.tenants.database.password_configured') : __('principal.tenants.database.password_missing') }}
                    · {{ __('principal.tenants.database.password_hint') }}
                </p>
                <button class="principal-button principal-button-primary justify-self-start" type="submit">{{ __('principal.tenants.database.save_credentials') }}</button>
            </form>

            <div class="grid gap-4 p-4 sm:p-5">
                <h4 class="text-sm font-semibold text-navy-900">{{ __('principal.tenants.database.actions_heading') }}</h4>
                <div class="flex flex-wrap gap-2">
                    <form method="post" action="{{ route('principal.tailadmin.tenants.database.test', $tenant) }}">
                        @csrf
                        <button class="principal-button" type="submit">{{ __('principal.tenants.database.test') }}</button>
                    </form>
                    <form method="post" action="{{ route('principal.tailadmin.tenants.database.migrate', $tenant) }}">
                        @csrf
                        <button class="principal-button principal-button-primary" type="submit">{{ __('principal.tenants.database.migrate') }}</button>
                    </form>
                </div>

                @if ($databaseProbe)
                    @php
                        $probeLines = [];
                        if ($databaseProbe['connected'] === true) {
                            $probeLines[] = __('principal.tenants.database.probe_connected');
                            if ($databaseProbe['database_present'] === true) {
                                $probeLines[] = __('principal.tenants.database.probe_database_present');
                            } else {
                                $probeLines[] = __('principal.tenants.database.probe_database_missing');
                            }
                        } else {
                            $probeLines[] = __('principal.tenants.database.probe_connection_failed');
                        }
                    @endphp
                    <div class="grid gap-2 text-sm">
                        <h5 class="font-semibold text-navy-900">{{ __('principal.tenants.database.probe_heading') }}</h5>
                        <ul class="list-inside list-disc">
                            @foreach ($probeLines as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($databaseResult)
                    <div class="grid gap-2 text-sm">
                        <h5 class="font-semibold text-navy-900">{{ __('principal.tenants.database.result_heading') }}</h5>
                        <p>{{ $databaseResult['ready'] ? __('principal.tenants.database.result_ready') : __('principal.tenants.database.result_failed') }}</p>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- --------------------------------------------------------------- Brands --}}
    <section class="principal-card">
        <div class="grid gap-4 border-b border-slate-200 p-4 sm:p-5">
            <h3 class="text-base font-semibold text-navy-900">{{ __('lba.tenants.brands') }}</h3>
            @if ($availableBrands !== [])
                <form method="post" action="{{ route('principal.tailadmin.tenants.brands.attach', $tenant) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="grid min-w-0 flex-1 gap-1 text-sm">
                        {{ __('lba.tenants.attach_brand') }}
                        <select name="brand_id" class="principal-input" required>
                            @foreach ($availableBrands as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex min-h-10 items-center gap-2 text-sm">
                        <input type="checkbox" name="is_primary" value="1"> {{ __('lba.brands.is_primary') }}
                    </label>
                    <button class="principal-button principal-button-primary" type="submit">{{ __('lba.tenants.attach_brand') }}</button>
                </form>
            @endif
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse ($brands as $brand)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
                    <div class="min-w-0">
                        <div class="wrap-break-word font-semibold text-navy-900">{{ $brand->name }}</div>
                        <div class="text-sm text-slate-600">{{ __('lba.brands.status_'.$brand->status) }}
                            @if ($brand->pivot->is_primary) · {{ __('lba.brands.is_primary') }} @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if (! $brand->pivot->is_primary)
                            <form method="post" action="{{ route('principal.tailadmin.tenants.brands.primary', [$tenant, $brand]) }}">
                                @csrf
                                @method('PATCH')
                                <button class="principal-button" type="submit">{{ __('lba.brands.make_primary') }}</button>
                            </form>
                        @endif
                        <form method="post" action="{{ route('principal.tailadmin.tenants.brands.detach', [$tenant, $brand]) }}">
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
    </section>
@endsection
