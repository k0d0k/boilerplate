@php
    $brands = $this->brands();
@endphp

<x-filament-panels::page>
    <style>
        .lba-br { --lba-br-bg:#0c1725; --lba-br-panel:#0f1d2f; --lba-br-line:rgba(255,255,255,0.10); --lba-br-fg:rgba(255,255,255,0.92); --lba-br-muted:rgba(255,255,255,0.55); --lba-br-teal:#0aa5a6; --lba-br-bright:#7fe3e0; background:var(--lba-br-bg); border:1px solid var(--lba-br-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-br-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-br__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-br-bright); }
        .lba-br__block { padding:0.875rem; border:1px solid var(--lba-br-line); border-radius:0.625rem; background:var(--lba-br-panel); }
        .lba-br__block-title { font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-br-muted); margin-bottom:0.625rem; }
        .lba-br__grid { display:grid; gap:0.75rem; grid-template-columns:repeat(2, minmax(0, 1fr)); }
        @media (max-width:64rem) { .lba-br__grid { grid-template-columns:1fr; } }
        .lba-br__field { display:flex; flex-direction:column; gap:0.25rem; font-size:0.75rem; color:var(--lba-br-muted); }
        .lba-br__input, .lba-br__select, .lba-br__textarea { padding:0.5rem 0.625rem; border:1px solid var(--lba-br-line); border-radius:0.5rem; background:#08111c; color:#fff; font-size:0.8125rem; }
        .lba-br__textarea { width:100%; min-height:3.5rem; }
        .lba-br__btn { display:inline-flex; align-items:center; gap:0.375rem; padding:0.5rem 0.875rem; border:1px solid var(--lba-br-line); border-radius:0.625rem; background:rgba(255,255,255,0.04); color:var(--lba-br-fg); font-size:0.8125rem; font-weight:600; cursor:pointer; }
        .lba-br__btn:hover { background:rgba(255,255,255,0.09); }
        .lba-br__btn--primary { border-color:transparent; background:var(--lba-br-teal); color:#fff; }
        .lba-br__btn--primary:hover { background:#0b8f90; }
        .lba-br__chip { display:inline-flex; align-items:center; padding:0.25rem 0.6rem; border:1px solid var(--lba-br-line); border-radius:999px; font-size:0.75rem; cursor:pointer; background:rgba(255,255,255,0.03); }
        .lba-br__chip.is-active { border-color:var(--lba-br-bright); color:var(--lba-br-bright); }
        .lba-br__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-br-line); background:rgba(255,255,255,0.04); color:var(--lba-br-muted); }
        .lba-br__empty { font-size:0.8125rem; color:var(--lba-br-muted); }
        .lba-br__logo-badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; background:rgba(74,222,128,0.18); color:#86efac; }
    </style>

    <div class="lba-br">
        <div>
            <p class="lba-br__eyebrow">{{ __('lba.brand_report.eyebrow') }}</p>
        </div>

        <div class="lba-br__notice">{{ __('lba.brand_report.notice') }}</div>

        <div class="lba-br__block">
            <p class="lba-br__block-title">{{ __('lba.brand_report.choose_brand') }}</p>
            @if ($brands->isEmpty())
                <p class="lba-br__empty">{{ __('lba.brand_report.no_brands') }}</p>
            @else
                <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                    @foreach ($brands as $brand)
                        <button type="button"
                                class="lba-br__chip {{ $brandId === (string) $brand->getKey() ? 'is-active' : '' }}"
                                wire:click="selectBrand('{{ $brand->getKey() }}')">
                            {{ $brand->name }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($brandId !== null)
            <div class="lba-br__block">
                <p class="lba-br__block-title">{{ __('lba.brand_report.identity') }}</p>
                <div class="lba-br__grid">
                    <label class="lba-br__field">
                        {{ __('lba.brand_report.display_name') }}
                        <input type="text" class="lba-br__input" wire:model="displayName">
                    </label>
                    <label class="lba-br__field">
                        {{ __('lba.brand_report.website') }}
                        <input type="text" class="lba-br__input" wire:model="website">
                    </label>
                    <label class="lba-br__field">
                        {{ __('lba.brand_report.contact') }}
                        <input type="text" class="lba-br__input" wire:model="contact">
                    </label>
                    <label class="lba-br__field">
                        {{ __('lba.brand_report.language') }}
                        <select class="lba-br__select" wire:model="language">
                            @foreach ($this->languageOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <label class="lba-br__field" style="margin-top:0.75rem;">
                    {{ __('lba.brand_report.address') }}
                    <input type="text" class="lba-br__input" wire:model="address">
                </label>
                <div class="lba-br__grid" style="margin-top:0.75rem;">
                    <label class="lba-br__field">
                        {{ __('lba.brand_report.header') }}
                        <textarea class="lba-br__textarea" wire:model="header"></textarea>
                    </label>
                    <label class="lba-br__field">
                        {{ __('lba.brand_report.footer') }}
                        <textarea class="lba-br__textarea" wire:model="footer"></textarea>
                    </label>
                </div>

                <div style="margin-top:0.875rem;">
                    <label class="lba-br__field">
                        {{ __('lba.brand_report.logo') }}
                        <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                    </label>
                    @if ($this->hasLogo())
                        <span class="lba-br__logo-badge" style="margin-top:0.375rem;">{{ __('lba.brand_report.logo_present') }}</span>
                    @endif
                    @error('logo') <p class="lba-br__notice">{{ $message }}</p> @enderror
                </div>

                <div style="margin-top:0.875rem;">
                    <button type="button" class="lba-br__btn lba-br__btn--primary" wire:click="save">{{ __('lba.brand_report.save') }}</button>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
