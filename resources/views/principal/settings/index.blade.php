@extends('principal.layouts.app', ['title' => __('principal.settings.title')])

@section('content')
    <div class="grid gap-5">
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('principal.settings.title') }}">
            <a class="principal-button {{ $tab === 'general' ? 'principal-button-primary' : '' }}"
                href="{{ route('principal.tailadmin.settings.index', ['tab' => 'general']) }}"
                @if ($tab === 'general') aria-current="page" @endif>{{ __('principal.settings.tabs.general') }}</a>
            <a class="principal-button {{ $tab === 'hosting' ? 'principal-button-primary' : '' }}"
                href="{{ route('principal.tailadmin.settings.index', ['tab' => 'hosting']) }}"
                @if ($tab === 'hosting') aria-current="page" @endif>{{ __('principal.settings.tabs.hosting') }}</a>
        </nav>

        @if ($tab === 'general')
            <section class="principal-card p-5">
                <form method="post" action="{{ route('principal.tailadmin.settings.general.update') }}" class="grid max-w-md gap-4">
                    @csrf
                    @method('PATCH')
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.settings.general.system_name') }}
                        <input class="principal-input" name="system_name" maxlength="255" value="{{ old('system_name', $systemName) }}" required>
                    </label>
                    <p class="text-xs text-slate-500">{{ __('principal.settings.general.system_name_hint') }}</p>
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.settings.general.default_locale') }}
                        <select name="default_locale" class="principal-input" required>
                            @foreach ($supportedLocales as $code)
                                <option value="{{ $code }}" @selected(old('default_locale', $locale) === $code)>{{ $localeLabels[$code] ?? $code }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="text-xs text-slate-500">{{ __('principal.settings.general.default_locale_hint') }}</p>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('principal.brands.save') }}</button>
                </form>
            </section>
        @else
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.settings.hosting.server_database_heading') }}</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    @foreach ([
                        'mysql_host' => $manualServer['host'],
                        'mysql_port' => $manualServer['port'],
                    ] as $label => $value)
                        <div>
                            <dt class="text-slate-500">{{ __('principal.settings.hosting.'.$label) }}</dt>
                            <dd class="break-all font-medium text-navy-900">{{ $value !== '' ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="text-xs text-slate-500">{{ __('principal.settings.hosting.server_database_hint') }}</p>
            </section>

            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.settings.hosting.status_heading') }}</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    @foreach ([
                        'host' => $cpanel['host'],
                        'port' => (string) $cpanel['port'],
                        'username' => $cpanel['username'],
                        'runtime_user' => $cpanel['runtime_user'],
                    ] as $label => $value)
                        <div>
                            <dt class="text-slate-500">{{ __('principal.settings.hosting.'.$label) }}</dt>
                            <dd class="break-all font-medium text-navy-900">{{ $value !== '' ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-slate-500">{{ __('principal.settings.hosting.status_provisioning') }}</dt>
                        <dd class="font-medium text-navy-900">{{ __($cpanel['enabled'] ? 'principal.settings.hosting.status_enabled' : 'principal.settings.hosting.status_disabled') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('principal.settings.hosting.mode') }}</dt>
                        <dd class="font-medium text-navy-900">{{ __('principal.settings.hosting.mode_'.$databaseMode) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('principal.settings.hosting.token_status') }}</dt>
                        <dd class="font-medium text-navy-900">{{ __('principal.settings.hosting.token_source_'.$cpanel['token_source']) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="principal-card p-5">
                <form method="post" action="{{ route('principal.tailadmin.settings.hosting.update') }}" class="grid gap-4">
                    @csrf
                    @method('PATCH')
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.settings.hosting.mode') }}
                        <select name="mode" class="principal-input">
                            @foreach (['cpanel', 'manual'] as $mode)
                                <option value="{{ $mode }}" @selected(old('mode', $databaseMode) === $mode)>{{ __('principal.settings.hosting.mode_'.$mode) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="text-xs text-slate-500">{{ __('principal.settings.hosting.mode_hint') }}</p>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $cpanel['enabled']))>
                        {{ __('principal.settings.hosting.enabled') }}
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">
                            {{ __('principal.settings.hosting.host') }}
                            <input class="principal-input" name="host" maxlength="255" value="{{ old('host', $cpanel['host']) }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('principal.settings.hosting.port') }}
                            <input class="principal-input" type="number" name="port" min="1" max="65535" value="{{ old('port', $cpanel['port']) }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('principal.settings.hosting.username') }}
                            <input class="principal-input" name="username" maxlength="64" value="{{ old('username', $cpanel['username']) }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('principal.settings.hosting.runtime_user') }}
                            <input class="principal-input" name="runtime_user" maxlength="64" value="{{ old('runtime_user', $cpanel['runtime_user']) }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('principal.settings.hosting.timeout') }}
                            <input class="principal-input" type="number" name="timeout" min="1" max="300" value="{{ old('timeout', $cpanel['timeout']) }}">
                        </label>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $cpanel['verify_tls']))>
                        {{ __('principal.settings.hosting.verify_tls') }}
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.settings.hosting.token') }}
                        {{-- Write-only: never pre-filled and never echoed back. --}}
                        <input class="principal-input" type="password" name="token" maxlength="255" autocomplete="new-password"
                            placeholder="{{ $cpanel['token_configured'] ? '••••••••' : '' }}">
                    </label>
                    <p class="text-xs text-slate-500">{{ __('principal.settings.hosting.token_hint') }}</p>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('principal.settings.hosting.save') }}</button>
                </form>
            </section>

            <section class="principal-card grid gap-4 p-5">
                <form method="post" action="{{ route('principal.tailadmin.settings.hosting.test') }}">
                    @csrf
                    <button class="principal-button">{{ __('principal.settings.hosting.test') }}</button>
                </form>
                @if ($probe)
                    @php
                        $lines = [];
                        if ($probe['configured'] === true) {
                            $lines[] = $probe['authenticated'] ? __('principal.settings.hosting.test_authenticated') : __('principal.settings.hosting.test_unauthenticated');
                            if ($probe['authenticated']) {
                                $lines[] = $probe['runtime_user_available'] ? __('principal.settings.hosting.test_runtime_user_available') : __('principal.settings.hosting.test_runtime_user_missing');
                            }
                        } else {
                            $lines[] = __('principal.settings.hosting.test_not_configured');
                        }
                    @endphp
                    <div class="grid gap-2 text-sm">
                        <h3 class="font-semibold text-navy-900">{{ __('principal.settings.hosting.test_heading') }}</h3>
                        <ul class="list-inside list-disc">
                            @foreach ($lines as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                            <li>{{ __('principal.settings.hosting.prefix_rule') }}:
                                {{ $probe['prefix_enabled'] ? __('principal.settings.hosting.prefix_enabled', ['account' => $probe['account']]) : __('principal.settings.hosting.prefix_disabled') }}</li>
                        </ul>
                    </div>
                @endif
            </section>
        @endif
    </div>
@endsection
