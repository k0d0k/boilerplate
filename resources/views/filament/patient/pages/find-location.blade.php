{{--
    Phase 8A — "Cari Lokasi LBA".

    Read-only list of the verified tenant's active branches. Only public-facing
    location details are shown; no patient, examination or staff data.
--}}
<x-filament-panels::page>
    @php($branches = $this->branches())

    @if ($branches->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.locations.empty') }}
        </p>
    @else
        <ul role="list" class="space-y-3">
            @foreach ($branches as $branch)
                <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="font-semibold text-gray-950 dark:text-white">
                        {{ $branch->name }}
                    </p>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->addressLine($branch) }}
                    </p>

                    @if (filled($branch->phone) || filled($branch->email))
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            @if (filled($branch->phone))
                                <span>{{ $branch->phone }}</span>
                            @endif
                            @if (filled($branch->phone) && filled($branch->email))
                                <span aria-hidden="true">·</span>
                            @endif
                            @if (filled($branch->email))
                                <span>{{ $branch->email }}</span>
                            @endif
                        </p>
                    @endif

                    <a
                        href="{{ $this->bookingUrl($branch) }}"
                        class="mt-3 inline-block text-sm font-semibold text-primary-600 underline dark:text-primary-400"
                    >
                        {{ __('lba.patient.locations.book') }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
