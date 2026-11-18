@extends('principal.layouts.app', ['title' => __($tenant->exists ? 'principal.tenants.edit' : 'principal.tenants.create')])

@section('content')
    <div class="grid max-w-3xl gap-5">
        <section class="principal-card p-5">
            <form method="post" action="{{ $tenant->exists ? route('principal.tailadmin.tenants.update', $tenant) : route('principal.tailadmin.tenants.store') }}" class="grid gap-4">
                @csrf
                @if ($tenant->exists)
                    @method('PATCH')
                @endif
                @foreach (['name', 'slug'] as $field)
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.tenants.'.$field) }}
                        <input class="principal-input" name="{{ $field }}" maxlength="255" required
                            value="{{ is_string(old($field, $tenant->{$field})) ? old($field, $tenant->{$field}) : '' }}">
                    </label>
                @endforeach
                <label class="grid gap-1 text-sm">
                    {{ __('lba.tenants.status') }}
                    <select name="status" class="principal-input" required>
                        @foreach (App\Http\Requests\Principal\SaveTenantRequest::STATUSES as $status)
                            <option value="{{ $status }}" @selected(old('status', $tenant->status) === $status)>{{ __('lba.tenants.status_'.$status) }}</option>
                        @endforeach
                    </select>
                </label>

                {{-- Brand link: uses the EXISTING Brand–Partner relation, so the
                     same form that records the partner also sets its brand and
                     which brand is primary. --}}
                <label class="grid gap-1 text-sm">
                    {{ __('principal.tenants.brand') }}
                    <select name="brand_id" class="principal-input">
                        <option value="">{{ __('principal.tenants.brand_none') }}</option>
                        @foreach ($brands as $id => $label)
                            <option value="{{ $id }}" @selected(old('brand_id', $primaryBrandId) === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_primary_brand" value="1" @checked(old('is_primary_brand', $brandIsPrimary))>
                    {{ __('principal.tenants.brand_primary') }}
                </label>

                {{-- "Database Manual": the operator records the physical MySQL
                     name they already created in cPanel plus its credential. The
                     fields are only submitted when mode = Manual; host and port
                     follow the server configuration, so no `.env` entry per
                     partner is needed. The password is WRITE-ONLY. --}}
                <fieldset class="grid gap-3 rounded-lg border border-slate-200 p-4">
                    <legend class="px-1 text-sm font-semibold text-navy-900">{{ __('principal.tenants.database_manual_heading') }}</legend>
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.tenants.database_mode') }}
                        <select name="database_mode" class="principal-input" data-database-mode>
                            <option value="">{{ __('principal.tenants.database_mode_none') }}</option>
                            <option value="{{ App\Platform\Settings\PlatformSettings::MODE_MANUAL }}"
                                @selected(old('database_mode', $configuredDatabaseName !== '' ? App\Platform\Settings\PlatformSettings::MODE_MANUAL : '') === App\Platform\Settings\PlatformSettings::MODE_MANUAL)>
                                {{ __('principal.tenants.database_mode_manual') }}
                            </option>
                        </select>
                    </label>
                    <div class="grid gap-3" data-database-manual>
                        <label class="grid gap-1 text-sm">
                            {{ __('principal.tenants.db_name') }}
                            <input class="principal-input" name="db_name" maxlength="64" value="{{ old('db_name', $configuredDatabaseName) }}">
                        </label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="grid gap-1 text-sm">
                                {{ __('principal.tenants.db_username') }}
                                <input class="principal-input" name="db_username" maxlength="64" value="{{ old('db_username', $credentialUsername) }}">
                            </label>
                            <label class="grid gap-1 text-sm">
                                {{ __('principal.tenants.db_password') }}
                                <input class="principal-input" type="password" name="db_password" maxlength="255" value="" autocomplete="new-password"
                                    placeholder="{{ $passwordConfigured ? '••••••••' : '' }}">
                            </label>
                        </div>
                        <p class="text-xs text-slate-500">{{ __('principal.tenants.database_manual_hint') }}</p>
                        <p class="text-xs text-slate-500">{{ __('principal.tenants.db_password_hint') }}</p>
                    </div>
                </fieldset>
                <div class="flex flex-wrap gap-2">
                    <button class="principal-button principal-button-primary">{{ __('principal.brands.save') }}</button>
                    <a class="principal-button" href="{{ route('principal.tailadmin.tenants.index') }}">{{ __('principal.brands.cancel') }}</a>
                    @if ($tenant->exists)
                        <a class="principal-button" href="{{ route('principal.tailadmin.tenants.relations', $tenant) }}">{{ __('principal.tenants.relations') }}</a>
                    @endif
                </div>
            </form>
        </section>
        @if ($tenant->exists)
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.tenants.database_status') }}</h2>
                <p class="text-sm">{{ __('principal.tenants.database_state_'.$database['state']) }}</p>
                @if ($database['reason'] !== '')
                    <p class="text-sm">{{ __('principal.tenants.provision_reason_'.$database['reason']) }}</p>
                @endif
                @if ($provisionResult)
                    @php
                        $provisionState = $provisionResult->ready
                            ? 'ready'
                            : ($provisionResult->error === 'concurrent_setup' ? 'processing' : 'failed');
                        $provisionReason = $provisionResult->error === 'recorded_database_mismatch' ? 'mismatch' : 'database_error';
                    @endphp
                    <p role="status" class="text-sm">{{ __('principal.tenants.provision_result_'.$provisionState) }}</p>
                    @if ($provisionState === 'failed')
                        <p class="text-sm">{{ __('principal.tenants.provision_reason_'.$provisionReason) }}</p>
                    @endif
                @endif
                @if ($database['state'] !== 'ready')
                    <form method="post" action="{{ route('principal.tailadmin.tenants.provision', $tenant) }}">
                        @csrf
                        <button class="principal-button justify-self-start">{{ __('principal.tenants.'.($database['retry'] ? 'provision_retry' : 'provision')) }}</button>
                    </form>
                @endif
            </section>
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('lba.tenants.setup_clinic') }}</h2>
                @if ($setupResult)
                    <p role="status" class="text-sm">{{ __('lba.tenants.'.($setupResult->succeeded() ? 'setup_complete' : 'setup_failed')) }}
                        {{ __('lba.tenants.setup_readiness') }} {{ __('lba.tenants.'.($setupResult->ready ? 'setup_ready' : 'setup_not_ready')) }}</p>
                    @if ($setupResult->activationUrl)
                        <p class="text-sm">{{ __('lba.tenants.setup_activation_ready') }}
                            <a class="break-all underline" href="{{ $setupResult->activationUrl }}">{{ $setupResult->activationUrl }}</a></p>
                    @endif
                @endif
                <form method="post" action="{{ route('principal.tailadmin.tenants.setup', $tenant) }}" class="grid gap-4">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.tenants.setup_admin_email') }}
                        <input class="principal-input" type="email" name="admin_email" maxlength="255">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.tenants.setup_admin_name') }}
                        <input class="principal-input" name="admin_name" maxlength="255">
                    </label>
                    <button class="principal-button justify-self-start">{{ __('lba.tenants.setup_clinic') }}</button>
                </form>
            </section>
        @endif
    </div>
@endsection
