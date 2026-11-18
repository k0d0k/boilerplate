@php($config = $this->currentConfig())

<x-filament-panels::page>
    <style>
        .lba-ai { --lba-ai-bg:#0c1725; --lba-ai-panel:#0f1d2f; --lba-ai-line:rgba(255,255,255,0.10); --lba-ai-fg:rgba(255,255,255,0.92); --lba-ai-muted:rgba(255,255,255,0.55); --lba-ai-bright:#7fe3e0; background:var(--lba-ai-bg); border:1px solid var(--lba-ai-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-ai-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-ai__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-ai-bright); }
        .lba-ai__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-ai-line); background:rgba(255,255,255,0.04); color:var(--lba-ai-muted); }
        .lba-ai__block { padding:0.875rem; border:1px solid var(--lba-ai-line); border-radius:0.625rem; background:var(--lba-ai-panel); }
        .lba-ai__label { display:block; font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-ai-muted); margin-bottom:0.375rem; }
        .lba-ai__input, .lba-ai__select { width:100%; background:#0a1420; color:var(--lba-ai-fg); border:1px solid var(--lba-ai-line); border-radius:0.5rem; padding:0.5rem 0.625rem; font-size:0.8125rem; }
        .lba-ai__grid { display:grid; gap:0.75rem; grid-template-columns:repeat(auto-fit, minmax(14rem, 1fr)); }
        .lba-ai__error { font-size:0.6875rem; color:#fca5a5; margin-top:0.25rem; }
        .lba-ai__badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; }
        .lba-ai__badge--success { background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-ai__badge--gray { background:rgba(255,255,255,0.12); color:var(--lba-ai-muted); }
        .lba-ai__check { display:flex; align-items:center; gap:0.5rem; font-size:0.8125rem; }
        .lba-ai__btn { display:inline-flex; align-items:center; gap:0.25rem; padding:0.4rem 0.875rem; border-radius:0.5rem; font-size:0.75rem; font-weight:600; border:1px solid rgba(10,165,166,0.5); background:rgba(10,165,166,0.25); color:var(--lba-ai-bright); cursor:pointer; }
    </style>

    <div class="lba-ai">
        <p class="lba-ai__eyebrow">{{ __('lba.principal.ai_providers.eyebrow') }}</p>
        <div class="lba-ai__notice">{{ __('lba.principal.ai_providers.notice') }}</div>

        <div class="lba-ai__block">
            <div class="lba-ai__grid">
                <div>
                    <label class="lba-ai__label" for="lba-ai-tenant">{{ __('lba.principal.ai_providers.choose_tenant') }}</label>
                    <select id="lba-ai-tenant" class="lba-ai__select" wire:model.live="tenantId">
                        <option value="">{{ __('lba.principal.ai_providers.select_placeholder') }}</option>
                        @foreach ($this->tenantOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="lba-ai__label" for="lba-ai-provider">{{ __('lba.principal.ai_providers.provider') }}</label>
                    <select id="lba-ai-provider" class="lba-ai__select" wire:model.live="provider">
                        @foreach ($this->providerOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        @if ($tenantId === null || $tenantId === '')
            <div class="lba-ai__block">
                <p class="lba-ai__notice">{{ __('lba.principal.ai_providers.no_tenant') }}</p>
            </div>
        @else
            <div class="lba-ai__block">
                <div class="lba-ai__grid">
                    <div>
                        <label class="lba-ai__label" for="lba-ai-base-url">{{ __('lba.principal.ai_providers.base_url') }}</label>
                        <input id="lba-ai-base-url" type="text" class="lba-ai__input" wire:model="baseUrl" placeholder="https://api.openai.com/v1" />
                        @error('baseUrl') <p class="lba-ai__error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="lba-ai__label" for="lba-ai-model">{{ __('lba.principal.ai_providers.model') }}</label>
                        <input id="lba-ai-model" type="text" class="lba-ai__input" wire:model="model" placeholder="gpt-4o-mini" />
                        @error('model') <p class="lba-ai__error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div style="margin-top:0.75rem;">
                    <label class="lba-ai__label" for="lba-ai-api-key">{{ __('lba.principal.ai_providers.api_key') }}</label>
                    <input id="lba-ai-api-key" type="password" class="lba-ai__input" wire:model="apiKey" autocomplete="new-password" placeholder="{{ __('lba.principal.ai_providers.api_key_placeholder') }}" />
                    @error('apiKey') <p class="lba-ai__error">{{ $message }}</p> @enderror
                </div>

                <div style="margin-top:0.75rem; display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                    <label class="lba-ai__check">
                        <input type="checkbox" wire:model="enabled" />
                        <span>{{ __('lba.principal.ai_providers.enabled') }}</span>
                    </label>

                    <span class="lba-ai__badge {{ ($config?->enabled === true) ? 'lba-ai__badge--success' : 'lba-ai__badge--gray' }}">
                        {{ ($config?->enabled === true) ? __('lba.principal.ai_providers.status_enabled') : __('lba.principal.ai_providers.status_disabled') }}
                    </span>
                </div>

                <div style="margin-top:1rem;">
                    <button type="button" class="lba-ai__btn" wire:click="save" wire:loading.attr="disabled">
                        {{ __('lba.principal.ai_providers.save') }}
                    </button>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
