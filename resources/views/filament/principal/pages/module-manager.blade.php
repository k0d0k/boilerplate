@php
    $rows = $this->rows();
@endphp

<x-filament-panels::page>
    <style>
        .lba-modules { --lba-modules-bg:#0c1725; --lba-modules-panel:#0f1d2f; --lba-modules-line:rgba(255,255,255,0.10); --lba-modules-fg:rgba(255,255,255,0.92); --lba-modules-muted:rgba(255,255,255,0.55); --lba-modules-bright:#7fe3e0; background:var(--lba-modules-bg); border:1px solid var(--lba-modules-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-modules-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-modules__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-modules-bright); }
        .lba-modules__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-modules-line); background:rgba(255,255,255,0.04); color:var(--lba-modules-muted); }
        .lba-modules__block { padding:0.875rem; border:1px solid var(--lba-modules-line); border-radius:0.625rem; background:var(--lba-modules-panel); }
        .lba-modules__label { display:block; font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-modules-muted); margin-bottom:0.375rem; }
        .lba-modules__select { width:100%; max-width:24rem; background:#0a1420; color:var(--lba-modules-fg); border:1px solid var(--lba-modules-line); border-radius:0.5rem; padding:0.5rem 0.625rem; font-size:0.8125rem; }
        .lba-modules__table { width:100%; border-collapse:collapse; font-size:0.8125rem; }
        .lba-modules__table th { text-align:left; font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--lba-modules-muted); padding:0.375rem 0.5rem; border-bottom:1px solid var(--lba-modules-line); }
        .lba-modules__table td { padding:0.5rem; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align:middle; }
        .lba-modules__badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; }
        .lba-modules__badge--success { background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-modules__badge--danger { background:rgba(248,113,113,0.18); color:#fca5a5; }
        .lba-modules__badge--warning { background:rgba(255,209,102,0.18); color:#ffd166; }
        .lba-modules__badge--gray { background:rgba(255,255,255,0.12); color:var(--lba-modules-muted); }
        .lba-modules__btn { display:inline-flex; align-items:center; gap:0.25rem; padding:0.25rem 0.625rem; border-radius:0.375rem; font-size:0.6875rem; font-weight:600; border:1px solid var(--lba-modules-line); background:rgba(255,255,255,0.06); color:var(--lba-modules-fg); cursor:pointer; }
        .lba-modules__btn--primary { background:rgba(10,165,166,0.25); border-color:rgba(10,165,166,0.5); color:var(--lba-modules-bright); }
        .lba-modules__btn--danger { background:rgba(248,113,113,0.16); border-color:rgba(248,113,113,0.4); color:#fca5a5; }
        .lba-modules__empty { font-size:0.8125rem; color:var(--lba-modules-muted); }
        .lba-modules__dep { font-size:0.6875rem; color:#ffd166; }
    </style>

    <div class="lba-modules" wire:poll.60s>
        <p class="lba-modules__eyebrow">{{ __('lba.modules.eyebrow') }}</p>
        <div class="lba-modules__notice">{{ __('lba.modules.notice') }}</div>

        <div class="lba-modules__block">
            <label class="lba-modules__label" for="lba-modules-tenant">{{ __('lba.modules.choose_tenant') }}</label>
            <select id="lba-modules-tenant" class="lba-modules__select" wire:model.live="tenantId">
                <option value="">{{ __('lba.modules.select_placeholder') }}</option>
                @foreach ($this->tenantOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="lba-modules__block">
            @if ($this->tenantId === null || $this->tenantId === '')
                <p class="lba-modules__empty">{{ __('lba.modules.no_tenant') }}</p>
            @elseif ($rows === [])
                <p class="lba-modules__empty">{{ __('lba.modules.no_modules') }}</p>
            @else
                <table class="lba-modules__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.modules.col_module') }}</th>
                            <th>{{ __('lba.modules.col_version') }}</th>
                            <th>{{ __('lba.modules.col_platform') }}</th>
                            <th>{{ __('lba.modules.col_installed') }}</th>
                            <th>{{ __('lba.modules.col_entitled') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr wire:key="module-{{ $row['key'] }}">
                                <td>
                                    <strong>{{ $row['name'] }}</strong><br>
                                    <span class="lba-modules__empty">{{ $row['key'] }}</span>
                                </td>
                                <td>{{ $row['version'] }}</td>
                                <td>
                                    <span class="lba-modules__badge lba-modules__badge--{{ $row['display_status'] === 'enabled' ? 'success' : 'gray' }}">
                                        {{ __('lba.modules.platform_status_'.$row['display_status']) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="lba-modules__badge lba-modules__badge--{{ $row['enabled'] ? 'success' : 'gray' }}">
                                        {{ $row['enabled'] ? __('lba.modules.yes') : __('lba.modules.no') }}
                                    </span>
                                </td>
                                <td>
                                    <span class="lba-modules__badge lba-modules__badge--{{ $row['entitled'] ? 'success' : 'gray' }}">
                                        {{ $row['entitled'] ? __('lba.modules.yes') : __('lba.modules.no') }}
                                    </span>
                                    @if ($row['dependency'] !== null)
                                        <div class="lba-modules__dep">{{ $this->reasonLabel($row['dependency']) }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($row['enabled'])
                                        <button type="button" class="lba-modules__btn lba-modules__btn--danger" wire:click="disable('{{ $row['key'] }}')">
                                            {{ __('lba.modules.disable') }}
                                        </button>
                                    @else
                                        <button type="button" class="lba-modules__btn lba-modules__btn--primary" wire:click="enable('{{ $row['key'] }}')">
                                            {{ __('lba.modules.enable') }}
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
