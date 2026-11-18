@extends('principal.layouts.app', ['title' => __('lba.events.title'), 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        <section class="principal-card">
            <div class="border-b border-slate-200 p-4">
                <h2 class="font-semibold text-navy-900">{{ __('lba.events.event_locations') }}</h2>
                <p class="text-sm text-slate-600">{{ __('lba.events.event_locations_hint') }}</p>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($eventLocations as $location)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-semibold">{{ $location->name }}</p>
                            <p class="text-sm text-slate-600">
                                {{ __('lba.locations.type_'.$location->location_type) }}
                                · {{ $location->operates_from?->translatedFormat('d M Y H:i') ?? '—' }} → {{ $location->operates_until?->translatedFormat('d M Y H:i') ?? '—' }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($location->isTentative())
                                <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-700">{{ __('lba.events.tentative') }}</span>
                            @elseif ($location->isActive())
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-700">{{ __('lba.events.confirmed') }}</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-600">{{ __('lba.branches.status_'.$location->status) }}</span>
                            @endif
                            <a class="principal-button" href="{{ route('clinic.tailadmin.events.show', $location->getKey()) }}">{{ __('lba.events.open_bookings') }}</a>
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.events.no_event_locations') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card">
            <form method="get" action="{{ route('clinic.tailadmin.events') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                <label class="grid gap-1 text-sm">
                    {{ __('lba.events.filter_location') }}
                    <select name="location" class="principal-input">
                        <option value="">{{ __('lba.events.all_locations') }}</option>
                        @foreach ($locations as $option)
                            <option value="{{ $option->getKey() }}" @selected((string) $option->getKey() === (string) $location)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="principal-button">{{ __('lba.events.apply') }}</button>
            </form>
            <ul class="divide-y divide-slate-100">
                @forelse ($bookings as $booking)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-semibold">{{ $booking->patient?->full_name ?? '—' }}</p>
                            <p class="text-sm text-slate-600">
                                {{ $booking->starts_at?->translatedFormat('d M Y H:i') ?? '—' }}
                                @if ($booking->branch) · {{ $booking->branch->name }} @endif
                                · {{ __('lba.appointments.status_'.$booking->status) }}
                            </p>
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.events.empty') }}</li>
                @endforelse
            </ul>
            @if ($bookings->hasPages())
                <div class="border-t border-slate-200 p-4">{{ $bookings->links() }}</div>
            @endif
        </section>
    </div>
@endsection
