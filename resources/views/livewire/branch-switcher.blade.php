{{--
    Phase 2B — Clinic branch identity + switcher.

    Rendered into the Clinic topbar next to the tenant switcher. States:
      - exactly one accessible branch: a static, non-interactive identity chip;
      - several accessible branches: a trigger that reveals a searchable listbox;
      - none, but the identity may administer branches: a link to create the
        tenant's first branch (avoids the branch-context deadlock for a freshly
        provisioned tenant);
      - none and no permission: renders nothing.

    The list is fully server-rendered: every option is a real focusable button
    and the switch is a CSRF-protected, server-verified round-trip.
--}}
@php
    use App\Platform\Branches\BranchOption;

    /** @var list<BranchOption> $options */
    /** @var \App\Platform\Branches\Models\TenantBranch|null $current */
    $currentId = $current?->getKey();
@endphp

<div class="lba-branch" wire:key="lba-branch-switcher">
    @if ($totalOptions > 1)
        <div
            class="lba-branch__wrap"
            x-data
            x-on:keydown.escape="$wire.close()"
        >
            <button
                type="button"
                class="lba-branch__trigger"
                wire:click="toggle"
                aria-haspopup="listbox"
                aria-expanded="{{ $open ? 'true' : 'false' }}"
                aria-label="{{ __('lba.branch_switcher.switch_aria') }}"
            >
                <span class="lba-branch__mark" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-map-pin" />
                </span>

                <span class="lba-branch__text">
                    <span class="lba-branch__name">
                        {{ $current?->name ?? __('lba.branch_switcher.choose') }}
                    </span>

                    @if ($current?->code)
                        <span class="lba-branch__code">{{ $current->code }}</span>
                    @endif
                </span>

                <span class="lba-branch__chevron" aria-hidden="true">
                    <x-filament::icon icon="heroicon-m-chevron-up-down" />
                </span>
            </button>

            @if ($open)
                <div class="lba-branch__panel">
                    <div class="lba-branch__search">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" aria-hidden="true" />

                        <input
                            type="search"
                            class="lba-branch__search-input"
                            placeholder="{{ __('lba.branch_switcher.search_placeholder') }}"
                            wire:model.live.debounce.250ms="query"
                            autocomplete="off"
                            aria-label="{{ __('lba.branch_switcher.search_aria') }}"
                        />
                    </div>

                    <ul class="lba-branch__list" role="listbox" aria-label="{{ __('lba.branch_switcher.list_aria') }}">
                        @forelse ($options as $option)
                            <li role="none">
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected="{{ $option->id === $currentId ? 'true' : 'false' }}"
                                    class="lba-branch__option{{ $option->id === $currentId ? ' is-current' : '' }}"
                                    wire:click="switchTo('{{ $option->id }}')"
                                >
                                    <span class="lba-branch__option-text">
                                        <span class="lba-branch__option-name">{{ $option->name }}</span>

                                        @if ($option->code)
                                            <span class="lba-branch__option-code">{{ $option->code }}</span>
                                        @endif
                                    </span>

                                    @if ($option->id === $currentId)
                                        <span class="lba-branch__option-check" aria-hidden="true">
                                            <x-filament::icon icon="heroicon-m-check" />
                                        </span>
                                    @endif
                                </button>
                            </li>
                        @empty
                            <li class="lba-branch__empty" role="presentation">
                                {{ __('lba.branch_switcher.empty') }}
                            </li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>
    @elseif ($current)
        <div class="lba-branch__wrap">
            <span class="lba-branch__static" aria-label="{{ __('lba.branch_switcher.current_aria') }}">
                <span class="lba-branch__mark" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-map-pin" />
                </span>

                <span class="lba-branch__text">
                    <span class="lba-branch__name">{{ $current->name }}</span>

                    @if ($current->code)
                        <span class="lba-branch__code">{{ $current->code }}</span>
                    @endif
                </span>
            </span>
        </div>
    @elseif ($canManage && $setupUrl)
        <a class="lba-branch__setup" href="{{ $setupUrl }}">
            <span class="lba-branch__mark" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-plus" />
            </span>

            <span class="lba-branch__text">
                <span class="lba-branch__name">{{ __('lba.branch_switcher.setup') }}</span>
            </span>
        </a>
    @endif
</div>
