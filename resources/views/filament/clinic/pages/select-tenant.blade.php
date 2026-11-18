{{--
    Phase 1B-2B — "Pilih Mitra" explicit tenant selection screen.

    Shown when an identity holds several eligible memberships: no tenant is ever
    chosen automatically, so the user must pick one. Every card is a real button
    that triggers the same server-verified switch used by the topbar switcher.
--}}
<x-filament-panels::page>
    <div class="lba-select">
        <section class="lba-select__hero">
            <p class="lba-hero__eyebrow">{{ __('lba.select_tenant.eyebrow') }}</p>
            <h1 class="lba-hero__title">{{ __('lba.select_tenant.title') }}</h1>
            <p class="lba-hero__subtitle">
                {{ __('lba.select_tenant.subtitle') }}
            </p>
        </section>

        <ul class="lba-select__list" role="list">
            @foreach ($this->getTenantOptions() as $option)
                <li>
                    <button
                        type="button"
                        class="lba-select__card"
                        wire:click="selectTenant('{{ $option->id }}')"
                    >
                        <span class="lba-select__mark" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-building-office-2" />
                        </span>

                        <span class="lba-select__text">
                            <span class="lba-select__name">{{ $option->name }}</span>

                            @if ($option->brandLabel)
                                <span class="lba-select__brand">{{ $option->brandLabel }}</span>
                            @endif
                        </span>

                        <span class="lba-select__cta" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-arrow-right" />
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</x-filament-panels::page>
