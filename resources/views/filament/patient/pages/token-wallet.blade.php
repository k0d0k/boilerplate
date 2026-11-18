<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('patient_wallet.summary') }}</x-slot>

        <dl class="grid gap-4 sm:grid-cols-3">
            @foreach ([
                'available' => $wallet?->balance_credits ?? 0,
                'reserved' => $wallet?->reserved_credits ?? 0,
                'consumed' => $wallet?->lifetime_consumed_credits ?? 0,
            ] as $label => $credits)
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('patient_wallet.' . $label) }}</dt>
                    <dd class="text-2xl font-semibold tabular-nums">{{ $credits }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($wallet === null)
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('patient_wallet.no_wallet') }}</p>
        @elseif (! $wallet->isActive())
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('patient_wallet.suspended') }}</p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('patient_wallet.recent_usage', ['limit' => 20]) }}</x-slot>

        <ul role="list" class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($usage as $turn)
                <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <time datetime="{{ $turn->recorded_at?->toIso8601String() }}">
                        {{ $turn->recorded_at?->translatedFormat('d M Y · H:i T') }}
                    </time>
                    <span class="font-medium tabular-nums">
                        {{ __('patient_wallet.credits_used', ['credits' => $turn->credit_units]) }}
                    </span>
                </li>
            @empty
                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('patient_wallet.no_usage') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
