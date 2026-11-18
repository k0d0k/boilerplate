{{--
    Phase 8A — "Pilih Klinik" explicit clinic selection screen.

    Shown when a patient identity holds several eligible memberships: no clinic
    is ever chosen automatically, so the patient must pick one. Every card is a
    real button that triggers the same server-verified switch used everywhere
    else in the panel.
--}}
<x-filament-panels::page>
    <div class="lba-select">
        <section class="lba-select__hero">
            <p class="lba-hero__eyebrow">{{ __('lba.patient_select.eyebrow') }}</p>
            <h1 class="lba-hero__title">{{ __('lba.patient_select.title') }}</h1>
            <p class="lba-hero__subtitle">
                {{ __('lba.patient_select.subtitle') }}
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
