@php
    $entitlements = $this->entitlementRows();
    $memberships = $this->memberships();
    $roleOptions = $this->assignableRoleOptions();
    $matrix = $this->roleMatrix();
@endphp

<x-filament-panels::page>
    <style>
        .lba-access { --lba-access-bg:#0c1725; --lba-access-panel:#0f1d2f; --lba-access-line:rgba(255,255,255,0.10); --lba-access-fg:rgba(255,255,255,0.92); --lba-access-muted:rgba(255,255,255,0.55); --lba-access-bright:#7fe3e0; background:var(--lba-access-bg); border:1px solid var(--lba-access-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-access-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-access__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-access-bright); }
        .lba-access__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-access-line); background:rgba(255,255,255,0.04); color:var(--lba-access-muted); }
        .lba-access__block { padding:0.875rem; border:1px solid var(--lba-access-line); border-radius:0.625rem; background:var(--lba-access-panel); }
        .lba-access__block-title { font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-access-muted); margin-bottom:0.625rem; }
        .lba-access__label { display:block; font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-access-muted); margin-bottom:0.375rem; }
        .lba-access__select { width:100%; max-width:24rem; background:#0a1420; color:var(--lba-access-fg); border:1px solid var(--lba-access-line); border-radius:0.5rem; padding:0.5rem 0.625rem; font-size:0.8125rem; }
        .lba-access__select--multi { width:100%; max-width:22rem; min-height:5.5rem; }
        .lba-access__table { width:100%; border-collapse:collapse; font-size:0.8125rem; }
        .lba-access__table th { text-align:left; font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--lba-access-muted); padding:0.375rem 0.5rem; border-bottom:1px solid var(--lba-access-line); }
        .lba-access__table td { padding:0.5rem; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align:top; }
        .lba-access__badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; }
        .lba-access__badge--success { background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-access__badge--gray { background:rgba(255,255,255,0.12); color:var(--lba-access-muted); }
        .lba-access__btn { display:inline-flex; align-items:center; gap:0.25rem; padding:0.25rem 0.625rem; border-radius:0.375rem; font-size:0.6875rem; font-weight:600; border:1px solid var(--lba-access-line); background:rgba(255,255,255,0.06); color:var(--lba-access-fg); cursor:pointer; }
        .lba-access__btn--primary { background:rgba(10,165,166,0.25); border-color:rgba(10,165,166,0.5); color:var(--lba-access-bright); }
        .lba-access__btn--danger { background:rgba(248,113,113,0.16); border-color:rgba(248,113,113,0.4); color:#fca5a5; }
        .lba-access__empty { font-size:0.8125rem; color:var(--lba-access-muted); }
        .lba-access__perm { display:inline-block; margin:0.0625rem 0.25rem 0.0625rem 0; padding:0.0625rem 0.4rem; border-radius:0.25rem; background:rgba(255,255,255,0.07); font-size:0.625rem; color:var(--lba-access-fg); }
    </style>

    <div class="lba-access">
        <p class="lba-access__eyebrow">{{ __('lba.feature_access.eyebrow') }}</p>
        <div class="lba-access__notice">{{ __('lba.feature_access.notice') }}</div>

        <div class="lba-access__block">
            <label class="lba-access__label" for="lba-access-tenant">{{ __('lba.feature_access.choose_tenant') }}</label>
            <select id="lba-access-tenant" class="lba-access__select" wire:model.live="tenantId">
                <option value="">{{ __('lba.feature_access.select_placeholder') }}</option>
                @foreach ($this->tenantOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        @if ($this->tenantId !== null && $this->tenantId !== '')
            <div class="lba-access__block">
                <p class="lba-access__block-title">{{ __('lba.feature_access.entitlements') }}</p>
                @if ($entitlements === [])
                    <p class="lba-access__empty">{{ __('lba.feature_access.no_modules') }}</p>
                @else
                    <table class="lba-access__table">
                        <thead>
                            <tr>
                                <th>{{ __('lba.modules.col_module') }}</th>
                                <th>{{ __('lba.feature_access.col_entitled') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entitlements as $row)
                                <tr wire:key="ent-{{ $row['key'] }}">
                                    <td><strong>{{ $row['name'] }}</strong><br><span class="lba-access__empty">{{ $row['key'] }}</span></td>
                                    <td>
                                        <span class="lba-access__badge lba-access__badge--{{ $row['granted'] ? 'success' : 'gray' }}">
                                            {{ $row['granted'] ? __('lba.modules.yes') : __('lba.modules.no') }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($row['granted'])
                                            <button type="button" class="lba-access__btn lba-access__btn--danger" wire:click="revoke('{{ $row['key'] }}')">
                                                {{ __('lba.feature_access.revoke') }}
                                            </button>
                                        @else
                                            <button type="button" class="lba-access__btn lba-access__btn--primary" wire:click="grant('{{ $row['key'] }}')">
                                                {{ __('lba.feature_access.grant') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="lba-access__block">
                <p class="lba-access__block-title">{{ __('lba.feature_access.members') }}</p>
                @if ($memberships->isEmpty())
                    <p class="lba-access__empty">{{ __('lba.feature_access.no_members') }}</p>
                @else
                    <table class="lba-access__table">
                        <thead>
                            <tr>
                                <th>{{ __('lba.tenants.member') }}</th>
                                <th>{{ __('lba.tenants.roles') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($memberships as $membership)
                                @php $mid = (string) $membership->getKey(); @endphp
                                <tr wire:key="mem-{{ $mid }}">
                                    <td>
                                        {{ $membership->user?->name ?? '—' }}<br>
                                        <span class="lba-access__empty">{{ $membership->user?->email }}</span>
                                    </td>
                                    <td>
                                        <select class="lba-access__select lba-access__select--multi" multiple wire:model="memberRoles.{{ $mid }}">
                                            @foreach ($roleOptions as $roleKey => $roleLabel)
                                                <option value="{{ $roleKey }}">{{ $roleLabel }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <button type="button" class="lba-access__btn" wire:click="saveRoles('{{ $mid }}')">
                                            {{ __('lba.feature_access.save_roles') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="lba-access__block">
                <p class="lba-access__block-title">{{ __('lba.feature_access.matrix') }}</p>
                <table class="lba-access__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.feature_access.col_role') }}</th>
                            <th>{{ __('lba.feature_access.col_permissions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matrix as $roleKey => $permissions)
                            <tr wire:key="role-{{ $roleKey }}">
                                <td><strong>{{ __("lba.roles.{$roleKey}") }}</strong></td>
                                <td>
                                    @forelse ($permissions as $permission)
                                        <span class="lba-access__perm">{{ $this->permissionLabel($permission) }}</span>
                                    @empty
                                        <span class="lba-access__empty">—</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="lba-access__block">
                <p class="lba-access__empty">{{ __('lba.feature_access.no_tenant') }}</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
