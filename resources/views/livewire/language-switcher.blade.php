{{--
    Phase 1B-3 — authenticated language selector.

    Rendered into every panel's topbar (PanelsRenderHook::USER_MENU_BEFORE) as a
    single shared Livewire component, so there is exactly one language selector
    per page and never a duplicate per view.

    Each option is a real focusable button (keyboard accessible by default) and
    the trigger carries an aria-label; the layout is responsive — the endonym
    label collapses to the icon on narrow viewports.
--}}
<div class="lba-lang" wire:key="lba-language-switcher">
    <div
        class="lba-lang__wrap"
        x-data
        x-on:keydown.escape="$wire.close()"
    >
        <button
            type="button"
            class="lba-lang__trigger"
            wire:click="toggle"
            aria-haspopup="listbox"
            aria-expanded="{{ $open ? 'true' : 'false' }}"
            aria-label="{{ __('lba.language.aria') }}"
        >
            <span class="lba-lang__mark" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-language" />
            </span>

            <span class="lba-lang__label">{{ $languages[$current] ?? strtoupper($current) }}</span>

            <span class="lba-lang__chevron" aria-hidden="true">
                <x-filament::icon icon="heroicon-m-chevron-up-down" />
            </span>
        </button>

        @if ($open)
            <div class="lba-lang__panel">
                <ul class="lba-lang__list" role="listbox" aria-label="{{ __('lba.language.choose') }}">
                    @foreach ($languages as $code => $label)
                        <li role="none">
                            <button
                                type="button"
                                role="option"
                                aria-selected="{{ $code === $current ? 'true' : 'false' }}"
                                class="lba-lang__option{{ $code === $current ? ' is-current' : '' }}"
                                wire:click="setLocale('{{ $code }}')"
                            >
                                <span class="lba-lang__option-label">{{ $label }}</span>

                                @if ($code === $current)
                                    <span class="lba-lang__option-check" aria-hidden="true">
                                        <x-filament::icon icon="heroicon-m-check" />
                                    </span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
