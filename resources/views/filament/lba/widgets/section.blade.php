@php
    /**
     * Shared premium section card. Rendered by \App\Filament\Shared\Widgets\DemoSectionWidget
     * which supplies every variable below from \App\Filament\Shared\Data\LbaDemoData.
     *
     * Variants: list | breakdown | metric | cards
     *
     * @var string $variant
     * @var string|null $heading
     * @var string|null $description
     * @var array<int, array<string, mixed>> $items
     * @var array<string, mixed>|null $primary
     * @var array<string, mixed>|null $secondary
     * @var int|string|null $progress
     * @var string|null $progressLabel
     * @var string|null $note
     */
    use Filament\Support\Icons\Heroicon;

    $variant = $variant ?? 'list';
    $items = $items ?? [];

    $itemIcons = [
        'chat' => Heroicon::OutlinedChatBubbleLeftRight,
        'sparkles' => Heroicon::OutlinedSparkles,
        'clock' => Heroicon::OutlinedClock,
        'pack' => Heroicon::OutlinedShoppingBag,
        'wallet' => Heroicon::OutlinedWallet,
    ];

    $percentageOf = static function (array $item): int {
        $max = (float) ($item['max'] ?? 0);
        $value = (float) ($item['value'] ?? 0);

        return $max > 0 ? (int) min(100, round(($value / $max) * 100)) : 0;
    };
@endphp

<x-filament-widgets::widget>
    <section class="lba-card">
        @if (filled($heading) || filled($description))
            <header class="lba-card__header">
                @if (filled($heading))
                    <h2 class="lba-card__title">{{ $heading }}</h2>
                @endif

                @if (filled($description))
                    <p class="lba-card__description">{{ $description }}</p>
                @endif
            </header>
        @endif

        <div class="lba-card__body">
            @switch($variant)
                @case('breakdown')
                    <ul class="lba-breakdown">
                        @foreach ($items as $item)
                            <li class="lba-breakdown__item">
                                <div class="lba-breakdown__top">
                                    <span class="lba-breakdown__label">{{ $item['label'] ?? '' }}</span>
                                    <span class="lba-breakdown__value">
                                        {{ $item['caption'] ?? ($item['value'] ?? '') }}
                                    </span>
                                </div>
                                <div class="lba-breakdown__track">
                                    <span
                                        class="lba-breakdown__fill lba-breakdown__fill--{{ $item['tone'] ?? 'navy' }}"
                                        style="--lba-bar: {{ $percentageOf($item) }}%"
                                    ></span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @break

                @case('metric')
                    <div class="lba-metric">
                        @if (filled($primary))
                            <div class="lba-metric__primary">
                                <p class="lba-metric__primary-label">{{ $primary['label'] ?? '' }}</p>
                                <p class="lba-metric__primary-value">
                                    {{ $primary['value'] ?? '' }}
                                    @if (filled($primary['unit'] ?? null))
                                        <span class="lba-metric__unit">{{ $primary['unit'] }}</span>
                                    @endif
                                </p>
                            </div>
                        @endif

                        @if ($progress !== null)
                            <div class="lba-metric__progress">
                                <div class="lba-metric__track">
                                    <span class="lba-metric__fill" style="--lba-bar: {{ (int) $progress }}%"></span>
                                </div>
                                @if (filled($progressLabel))
                                    <p class="lba-metric__progress-label">{{ $progressLabel }}</p>
                                @endif
                            </div>
                        @endif

                        @if (filled($secondary))
                            <div class="lba-metric__secondary">
                                <p class="lba-metric__secondary-label">{{ $secondary['label'] ?? '' }}</p>
                                <p class="lba-metric__secondary-value">
                                    {{ $secondary['value'] ?? '' }}
                                    @if (filled($secondary['unit'] ?? null))
                                        <span class="lba-metric__unit">{{ $secondary['unit'] }}</span>
                                    @endif
                                </p>
                            </div>
                        @endif
                    </div>
                @break

                @case('cards')
                    <ul class="lba-tiles">
                        @foreach ($items as $item)
                            <li class="lba-tile">
                                <span class="lba-tile__icon" aria-hidden="true">
                                    <x-filament::icon :icon="$itemIcons[$item['icon'] ?? ''] ?? Heroicon::OutlinedSparkles" />
                                </span>

                                <div class="lba-tile__text">
                                    <p class="lba-tile__title">{{ $item['title'] ?? '' }}</p>
                                    @if (filled($item['subtitle'] ?? null))
                                        <p class="lba-tile__subtitle">{{ $item['subtitle'] }}</p>
                                    @endif
                                </div>

                                @if (filled($item['badge'] ?? null))
                                    <span class="lba-badge lba-badge--teal">{{ $item['badge'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @break

                @default
                    @if ($items === [])
                        {{-- Clear empty state: the activity card must explain the
                             blank rather than render an empty list shell. --}}
                        <p class="text-sm text-gray-500">{{ __('lba.widget.empty') }}</p>
                    @else
                        <ul class="lba-list">
                            @foreach ($items as $item)
                                <li class="lba-list__item">
                                    <div class="lba-list__main">
                                        <p class="lba-list__title">{{ $item['title'] ?? '' }}</p>
                                        @if (filled($item['subtitle'] ?? null))
                                            <p class="lba-list__subtitle">{{ $item['subtitle'] }}</p>
                                        @endif
                                    </div>

                                    <div class="lba-list__meta">
                                        @if (filled($item['badge']['label'] ?? null))
                                            <span class="lba-badge lba-badge--{{ $item['badge']['tone'] ?? 'slate' }}">
                                                {{ $item['badge']['label'] }}
                                            </span>
                                        @endif

                                        @if (filled($item['meta'] ?? null))
                                            <span class="lba-list__time">{{ $item['meta'] }}</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
            @endswitch
        </div>

        @if (filled($note))
            <p class="lba-card__note">{{ $note }}</p>
        @endif
    </section>
</x-filament-widgets::widget>
