@php
    /**
     * Shared premium hero band. Rendered by \App\Filament\Shared\Widgets\HeroSectionWidget
     * which supplies every variable below from \App\Filament\Shared\Data\LbaDemoData.
     *
     * @var string|null $eyebrow
     * @var string|null $title
     * @var string|null $subtitle
     * @var array<int, array{label: string, tone?: string}> $chips
     * @var string|null $note
     * @var string|null $userName
     */
    use Filament\Support\Icons\Heroicon;

    $chips = $chips ?? [];
@endphp

<x-filament-widgets::widget>
    <section class="lba-hero" aria-labelledby="lba-hero-title">
        <span class="lba-hero__glow" aria-hidden="true"></span>

        <div class="lba-hero__body">
            @if (filled($eyebrow))
                <p class="lba-hero__eyebrow">{{ $eyebrow }}</p>
            @endif

            @if (filled($userName))
                <p class="lba-hero__greeting">{{ __('lba.hero.greeting', ['name' => $userName]) }}</p>
            @endif

            @if (filled($title))
                <h1 id="lba-hero-title" class="lba-hero__title">{{ $title }}</h1>
            @endif

            @if (filled($subtitle))
                <p class="lba-hero__subtitle">{{ $subtitle }}</p>
            @endif

            @if (filled($chips))
                <ul class="lba-hero__chips">
                    @foreach ($chips as $chip)
                        <li class="lba-hero__chip lba-hero__chip--{{ $chip['tone'] ?? 'slate' }}">
                            <span class="lba-hero__chip-dot" aria-hidden="true"></span>
                            {{ $chip['label'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if (filled($note))
            <p class="lba-hero__note">
                <x-filament::icon :icon="Heroicon::OutlinedInformationCircle" class="lba-hero__note-icon" />
                <span>{{ $note }}</span>
            </p>
        @endif
    </section>
</x-filament-widgets::widget>
