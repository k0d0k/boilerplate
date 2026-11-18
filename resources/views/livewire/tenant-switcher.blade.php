{{--
    Phase 1B-2B — Clinic tenant identity + switcher.

    Rendered into the Clinic topbar (PanelsRenderHook::USER_MENU_BEFORE). States:
      - exactly one eligible membership: a static, non-interactive identity chip;
      - several eligible memberships: a trigger that reveals a searchable listbox;
      - none: renders nothing (the panel guard denies operational access, so the
        switcher is never reached).

    The list is fully server-rendered: the search input drives a small Livewire
    round-trip, so filtering and selection keep working without custom JS and
    every option is a real focusable button (keyboard accessible by default).
--}}
@php
    use App\Platform\Access\TenantOption;

    /** @var list<TenantOption> $options */
    /** @var \App\Models\Tenant|null $current */
    $currentId = $current?->getKey();
@endphp

<div class="lba-tenant" wire:key="lba-tenant-switcher">
    @if ($totalOptions > 1)
        <div
            class="lba-tenant__wrap"
            x-data
            x-on:keydown.escape="$wire.close()"
        >
            <button
                type="button"
                class="lba-tenant__trigger"
                wire:click="toggle"
                aria-haspopup="listbox"
                aria-expanded="{{ $open ? 'true' : 'false' }}"
                aria-label="{{ __('lba.switcher.switch_aria') }}"
            >
                <span class="lba-tenant__mark" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-building-office-2" />
                </span>

                <span class="lba-tenant__text">
                    <span class="lba-tenant__name">
                        {{ $current?->name ?? __('lba.switcher.choose') }}
                    </span>

                    @if ($currentBrand)
                        <span class="lba-tenant__brand">{{ $currentBrand }}</span>
                    @endif
                </span>

                <span class="lba-tenant__chevron" aria-hidden="true">
                    <x-filament::icon icon="heroicon-m-chevron-up-down" />
                </span>
            </button>

            @if ($open)
                <div class="lba-tenant__panel">
                    <div class="lba-tenant__search">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" aria-hidden="true" />

                        <input
                            type="search"
                            class="lba-tenant__search-input"
                            placeholder="{{ __('lba.switcher.search_placeholder') }}"
                            wire:model.live.debounce.250ms="query"
                            autocomplete="off"
                            aria-label="{{ __('lba.switcher.search_aria') }}"
                        />
                    </div>

                    <ul class="lba-tenant__list" role="listbox" aria-label="{{ __('lba.switcher.list_aria') }}">
                        @forelse ($options as $option)
                            <li role="none">
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected="{{ $option->id === $currentId ? 'true' : 'false' }}"
                                    class="lba-tenant__option{{ $option->id === $currentId ? ' is-current' : '' }}"
                                    wire:click="switchTo('{{ $option->id }}')"
                                >
                                    <span class="lba-tenant__option-text">
                                        <span class="lba-tenant__option-name">{{ $option->name }}</span>

                                        @if ($option->brandLabel)
                                            <span class="lba-tenant__option-brand">{{ $option->brandLabel }}</span>
                                        @endif
                                    </span>

                                    @if ($option->id === $currentId)
                                        <span class="lba-tenant__option-check" aria-hidden="true">
                                            <x-filament::icon icon="heroicon-m-check" />
                                        </span>
                                    @endif
                                </button>
                            </li>
                        @empty
                            <li class="lba-tenant__empty" role="presentation">
                                {{ __('lba.switcher.empty') }}
                            </li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>
    @elseif ($current)
        <div class="lba-tenant__wrap">
            <span class="lba-tenant__static" aria-label="{{ __('lba.switcher.current_aria') }}">
                <span class="lba-tenant__mark" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-building-office-2" />
                </span>

                <span class="lba-tenant__text">
                    <span class="lba-tenant__name">{{ $current->name }}</span>

                    @if ($currentBrand)
                        <span class="lba-tenant__brand">{{ $currentBrand }}</span>
                    @endif
                </span>
            </span>
        </div>
    @endif
</div>
