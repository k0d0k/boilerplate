@php
    $rows = $this->rows();
@endphp

<x-filament-panels::page>
    <style>
        .lba-trusted { --lba-trusted-bg:#0c1725; --lba-trusted-panel:#0f1d2f; --lba-trusted-line:rgba(255,255,255,0.10); --lba-trusted-fg:rgba(255,255,255,0.92); --lba-trusted-muted:rgba(255,255,255,0.55); --lba-trusted-bright:#7fe3e0; background:var(--lba-trusted-bg); border:1px solid var(--lba-trusted-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-trusted-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-trusted__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-trusted-bright); }
        .lba-trusted__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-trusted-line); background:rgba(255,255,255,0.04); color:var(--lba-trusted-muted); }
        .lba-trusted__block { padding:0.875rem; border:1px solid var(--lba-trusted-line); border-radius:0.625rem; background:var(--lba-trusted-panel); overflow-x:auto; }
        .lba-trusted__table { width:100%; border-collapse:collapse; font-size:0.8125rem; }
        .lba-trusted__table th { text-align:left; font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--lba-trusted-muted); padding:0.375rem 0.5rem; border-bottom:1px solid var(--lba-trusted-line); white-space:nowrap; }
        .lba-trusted__table td { padding:0.5rem; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align:top; }
        .lba-trusted__key { display:block; font-size:0.6875rem; color:var(--lba-trusted-muted); }
        .lba-trusted__meta { font-size:0.6875rem; color:var(--lba-trusted-muted); }
        .lba-trusted__badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; }
        .lba-trusted__badge--success { background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-trusted__badge--danger { background:rgba(248,113,113,0.18); color:#fca5a5; }
        .lba-trusted__badge--warning { background:rgba(255,209,102,0.18); color:#ffd166; }
        .lba-trusted__badge--gray { background:rgba(255,255,255,0.12); color:var(--lba-trusted-muted); }
        .lba-trusted__btn { display:inline-flex; align-items:center; gap:0.25rem; padding:0.25rem 0.625rem; border-radius:0.375rem; font-size:0.6875rem; font-weight:600; border:1px solid var(--lba-trusted-line); background:rgba(255,255,255,0.06); color:var(--lba-trusted-fg); cursor:pointer; }
        .lba-trusted__btn--primary { background:rgba(10,165,166,0.25); border-color:rgba(10,165,166,0.5); color:var(--lba-trusted-bright); }
        .lba-trusted__btn--danger { background:rgba(248,113,113,0.16); border-color:rgba(248,113,113,0.4); color:#fca5a5; }
        .lba-trusted__empty { font-size:0.8125rem; color:var(--lba-trusted-muted); }
        .lba-trusted__dep { font-size:0.6875rem; color:#ffd166; }
    </style>

    <div class="lba-trusted" wire:poll.60s>
        <p class="lba-trusted__eyebrow">{{ __('lba.trusted_modules.eyebrow') }}</p>
        <div class="lba-trusted__notice">{{ __('lba.trusted_modules.notice') }}</div>

        <div class="lba-trusted__block">
            @if ($rows === [])
                <p class="lba-trusted__empty">{{ __('lba.trusted_modules.empty') }}</p>
            @else
                <table class="lba-trusted__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.trusted_modules.col_module') }}</th>
                            <th>{{ __('lba.trusted_modules.col_version') }}</th>
                            <th>{{ __('lba.trusted_modules.col_min_platform') }}</th>
                            <th>{{ __('lba.trusted_modules.col_compatible') }}</th>
                            <th>{{ __('lba.trusted_modules.col_scopes') }}</th>
                            <th>{{ __('lba.trusted_modules.col_dependencies') }}</th>
                            <th>{{ __('lba.trusted_modules.col_locales') }}</th>
                            <th>{{ __('lba.trusted_modules.col_entitlement') }}</th>
                            <th>{{ __('lba.trusted_modules.col_global') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr wire:key="trusted-{{ $row['key'] }}">
                                <td>
                                    <strong>{{ $row['name'] }}</strong>
                                    <span class="lba-trusted__key">{{ $row['key'] }}</span>
                                </td>
                                <td>{{ $row['version'] }}</td>
                                <td>{{ $row['min_platform'] }}</td>
                                <td>
                                    <span class="lba-trusted__badge lba-trusted__badge--{{ $row['compatible'] ? 'success' : 'danger' }}">
                                        {{ $row['compatible'] ? __('lba.trusted_modules.yes') : __('lba.trusted_modules.no') }}
                                    </span>
                                </td>
                                <td class="lba-trusted__meta">{{ $row['scopes'] === [] ? __('lba.trusted_modules.none') : implode(', ', $row['scopes']) }}</td>
                                <td class="lba-trusted__meta">
                                    {{ $row['dependencies'] === [] ? __('lba.trusted_modules.none') : implode(', ', $row['dependencies']) }}
                                    @if ($row['dependency'] !== null)
                                        <div class="lba-trusted__dep">{{ $this->reasonLabel($row['dependency']) }}</div>
                                    @endif
                                </td>
                                <td class="lba-trusted__meta">{{ $row['locales'] === [] ? __('lba.trusted_modules.none') : implode(', ', $row['locales']) }}</td>
                                <td class="lba-trusted__meta">{{ $this->entitlementLabel($row['entitlement']) }}</td>
                                <td>
                                    <span class="lba-trusted__badge lba-trusted__badge--{{ $row['enabled'] ? 'success' : 'gray' }}">
                                        {{ $row['enabled'] ? __('lba.trusted_modules.enabled') : __('lba.trusted_modules.disabled') }}
                                    </span>
                                    @if ($row['enabled'])
                                        <span class="lba-trusted__badge lba-trusted__badge--{{ $row['healthy'] ? 'success' : 'warning' }}">
                                            {{ $row['healthy'] ? __('lba.trusted_modules.healthy') : __('lba.trusted_modules.unhealthy') }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($row['enabled'])
                                        <button type="button" class="lba-trusted__btn lba-trusted__btn--danger" wire:click="disableGlobally('{{ $row['key'] }}')">
                                            {{ __('lba.trusted_modules.disable_globally') }}
                                        </button>
                                    @else
                                        <button type="button" class="lba-trusted__btn lba-trusted__btn--primary" wire:click="enableGlobally('{{ $row['key'] }}')">
                                            {{ __('lba.trusted_modules.enable_globally') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
