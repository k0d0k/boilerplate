@php
    $locales = $this->locales();
    $defaultLocale = $this->defaultLocale();
    $brandDefaults = $this->brandDefaults();
    $gateways = $this->gateways();
    $roleMatrix = $this->platformRoleMatrix();
    $identities = $this->identities();
    $roleOptions = $this->principalRoleOptions();
    $candidateUsers = $this->candidateUsers();
@endphp

<x-filament-panels::page>
    <style>
        .lba-settings { --lba-set-bg:#0c1725; --lba-set-panel:#0f1d2f; --lba-set-line:rgba(255,255,255,0.10); --lba-set-fg:rgba(255,255,255,0.92); --lba-set-muted:rgba(255,255,255,0.55); --lba-set-bright:#7fe3e0; background:var(--lba-set-bg); border:1px solid var(--lba-set-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-set-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-settings__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-set-bright); }
        .lba-settings__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-set-line); background:rgba(255,255,255,0.04); color:var(--lba-set-muted); }
        .lba-settings__block { padding:0.875rem; border:1px solid var(--lba-set-line); border-radius:0.625rem; background:var(--lba-set-panel); }
        .lba-settings__block-title { font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-set-muted); margin-bottom:0.625rem; }
        .lba-settings__label { display:block; font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-set-muted); margin-bottom:0.375rem; }
        .lba-settings__select { width:100%; max-width:24rem; background:#0a1420; color:var(--lba-set-fg); border:1px solid var(--lba-set-line); border-radius:0.5rem; padding:0.5rem 0.625rem; font-size:0.8125rem; }
        .lba-settings__table { width:100%; border-collapse:collapse; font-size:0.8125rem; }
        .lba-settings__table th { text-align:left; font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--lba-set-muted); padding:0.375rem 0.5rem; border-bottom:1px solid var(--lba-set-line); }
        .lba-settings__table td { padding:0.5rem; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align:top; }
        .lba-settings__badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; }
        .lba-settings__badge--success { background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-settings__badge--warn { background:rgba(251,191,36,0.18); color:#fcd34d; }
        .lba-settings__badge--gray { background:rgba(255,255,255,0.12); color:var(--lba-set-muted); }
        .lba-settings__btn { display:inline-flex; align-items:center; gap:0.25rem; padding:0.25rem 0.625rem; border-radius:0.375rem; font-size:0.6875rem; font-weight:600; border:1px solid var(--lba-set-line); background:rgba(255,255,255,0.06); color:var(--lba-set-fg); cursor:pointer; }
        .lba-settings__btn--primary { background:rgba(10,165,166,0.25); border-color:rgba(10,165,166,0.5); color:var(--lba-set-bright); }
        .lba-settings__btn--danger { background:rgba(248,113,113,0.16); border-color:rgba(248,113,113,0.4); color:#fca5a5; }
        .lba-settings__empty { font-size:0.8125rem; color:var(--lba-set-muted); }
        .lba-settings__perm { display:inline-block; margin:0.0625rem 0.25rem 0.0625rem 0; padding:0.0625rem 0.4rem; border-radius:0.25rem; background:rgba(255,255,255,0.07); font-size:0.625rem; color:var(--lba-set-fg); }
        .lba-settings__row { display:flex; flex-wrap:wrap; gap:0.75rem; align-items:flex-end; }
        .lba-settings__field { display:flex; flex-direction:column; gap:0.25rem; }
        .lba-settings__roles { display:flex; flex-wrap:wrap; gap:0.375rem; }
        .lba-settings__role { display:inline-flex; align-items:center; gap:0.375rem; padding:0.125rem 0.25rem 0.125rem 0.5rem; border-radius:999px; background:rgba(255,255,255,0.07); font-size:0.6875rem; }
    </style>

    <div class="lba-settings">
        <p class="lba-settings__eyebrow">{{ __('lba.settings.eyebrow') }}</p>
        <div class="lba-settings__notice">{{ __('lba.settings.notice') }}</div>

        {{-- ------------------------------------------- Platform configuration --}}
        <div class="lba-settings__block">
            <p class="lba-settings__block-title">{{ __('lba.settings.configuration_heading') }}</p>
            <p class="lba-settings__label">{{ __('lba.settings.default_locale') }}: <strong>{{ $locales[$defaultLocale] ?? $defaultLocale }}</strong></p>
            <table class="lba-settings__table">
                <thead>
                    <tr>
                        <th>{{ __('lba.settings.col_locale') }}</th>
                        <th>{{ __('lba.settings.col_locale_label') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($locales as $code => $label)
                        <tr wire:key="locale-{{ $code }}">
                            <td><strong>{{ $code }}</strong></td>
                            <td>{{ $label }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="lba-settings__block">
            <p class="lba-settings__block-title">{{ __('lba.settings.brand_defaults_heading') }}</p>
            @if ($brandDefaults === [])
                <p class="lba-settings__empty">{{ __('lba.settings.brand_defaults_empty') }}</p>
            @else
                <table class="lba-settings__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.settings.col_brand') }}</th>
                            <th>{{ __('lba.settings.col_locale') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($brandDefaults as $brand => $locale)
                            <tr wire:key="brand-{{ $brand }}">
                                <td>{{ $brand }}</td>
                                <td>{{ $locales[$locale] ?? $locale }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="lba-settings__block">
            <p class="lba-settings__block-title">{{ __('lba.settings.gateways_heading') }}</p>
            @if ($gateways === [])
                <p class="lba-settings__empty">{{ __('lba.settings.gateways_empty') }}</p>
            @else
                <table class="lba-settings__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.settings.col_gateway') }}</th>
                            <th>{{ __('lba.settings.col_mode') }}</th>
                            <th>{{ __('lba.settings.col_enabled') }}</th>
                            <th>{{ __('lba.settings.col_verified') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($gateways as $gateway)
                            <tr wire:key="gateway-{{ $gateway['provider'] }}">
                                <td><strong>{{ $gateway['label'] }}</strong><br><span class="lba-settings__empty">{{ $gateway['provider'] }}</span></td>
                                <td>{{ __('lba.settings.mode_'.$gateway['mode']) }}</td>
                                <td>
                                    <span class="lba-settings__badge lba-settings__badge--{{ $gateway['enabled'] ? 'success' : 'gray' }}">
                                        {{ $gateway['enabled'] ? __('lba.settings.yes') : __('lba.settings.no') }}
                                    </span>
                                </td>
                                <td>
                                    <span class="lba-settings__badge lba-settings__badge--{{ $gateway['verified'] ? 'success' : 'warn' }}">
                                        {{ $gateway['verified'] ? __('lba.settings.yes') : __('lba.settings.no') }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ------------------------------------------------------- RBAC admin --}}
        <div class="lba-settings__block">
            <p class="lba-settings__block-title">{{ __('lba.settings.rbac_heading') }}</p>
            <p class="lba-settings__empty">{{ __('lba.settings.rbac_notice') }}</p>
        </div>

        <div class="lba-settings__block">
            <p class="lba-settings__block-title">{{ __('lba.settings.roles_heading') }}</p>
            <table class="lba-settings__table">
                <thead>
                    <tr>
                        <th>{{ __('lba.settings.col_role') }}</th>
                        <th>{{ __('lba.settings.col_permissions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roleMatrix as $roleKey => $permissions)
                        <tr wire:key="role-{{ $roleKey }}">
                            <td><strong>{{ $this->roleLabel($roleKey) }}</strong></td>
                            <td>
                                @forelse ($permissions as $permission)
                                    <span class="lba-settings__perm">{{ $this->permissionLabel($permission) }}</span>
                                @empty
                                    <span class="lba-settings__empty">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="lba-settings__block">
            <p class="lba-settings__block-title">{{ __('lba.settings.identities_heading') }}</p>
            @if ($identities->isEmpty())
                <p class="lba-settings__empty">{{ __('lba.settings.identities_empty') }}</p>
            @else
                <table class="lba-settings__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.settings.col_identity') }}</th>
                            <th>{{ __('lba.settings.col_role') }}</th>
                            <th>{{ __('lba.settings.col_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($identities as $identity)
                            @php $uid = (string) $identity->getKey(); @endphp
                            <tr wire:key="identity-{{ $uid }}">
                                <td>
                                    {{ $identity->name }}<br>
                                    <span class="lba-settings__empty">{{ $identity->email }}</span>
                                </td>
                                <td>
                                    <div class="lba-settings__roles">
                                        @foreach ($identity->principalRoleAssignments as $assignment)
                                            @php $rKey = $assignment->role?->key; @endphp
                                            @if ($rKey !== null)
                                                <span class="lba-settings__role" wire:key="assign-{{ $assignment->getKey() }}">
                                                    {{ $this->roleLabel($rKey) }}
                                                    <span class="lba-settings__badge lba-settings__badge--{{ $assignment->status === 'active' ? 'success' : ($assignment->status === 'pending' ? 'warn' : 'gray') }}">
                                                        {{ $this->assignmentStatusLabel($assignment->status) }}
                                                    </span>
                                                    @if ($assignment->status !== 'revoked')
                                                        <button type="button" class="lba-settings__btn lba-settings__btn--danger" wire:click="revokeRole('{{ $uid }}', '{{ $rKey }}')">
                                                            {{ __('lba.settings.revoke') }}
                                                        </button>
                                                    @endif
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="lba-settings__btn" wire:click="$set('grantUserId', '{{ $uid }}')">
                                        {{ __('lba.settings.assign') }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="lba-settings__block">
            <p class="lba-settings__block-title">{{ __('lba.settings.grant_heading') }}</p>
            <div class="lba-settings__row">
                <div class="lba-settings__field">
                    <label class="lba-settings__label" for="lba-set-user">{{ __('lba.settings.choose_user') }}</label>
                    <select id="lba-set-user" class="lba-settings__select" wire:model="grantUserId">
                        <option value="">{{ __('lba.settings.select_placeholder') }}</option>
                        @foreach ($candidateUsers as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lba-settings__field">
                    <label class="lba-settings__label" for="lba-set-role">{{ __('lba.settings.choose_role') }}</label>
                    <select id="lba-set-role" class="lba-settings__select" wire:model="grantRoleKey">
                        <option value="">{{ __('lba.settings.select_placeholder') }}</option>
                        @foreach ($roleOptions as $roleKey => $roleLabel)
                            <option value="{{ $roleKey }}">{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" class="lba-settings__btn lba-settings__btn--primary" wire:click="grantRole">
                    {{ __('lba.settings.grant') }}
                </button>
            </div>
        </div>
    </div>
</x-filament-panels::page>
