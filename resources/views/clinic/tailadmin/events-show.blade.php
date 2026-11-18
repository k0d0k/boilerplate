@extends('principal.layouts.app', ['title' => $location->name, 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        <section class="principal-card">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 p-4">
                <div class="min-w-0">
                    <h2 class="wrap-break-word font-semibold text-navy-900">{{ $location->name }}</h2>
                    <p class="text-sm text-slate-600">
                        {{ __('lba.locations.type_'.$location->location_type) }}
                        · {{ __('lba.branches.status_'.$location->status) }}
                        @if (! $location->isPermanent())
                            · {{ $location->operates_from?->translatedFormat('d M Y H:i') ?? '—' }} → {{ $location->operates_until?->translatedFormat('d M Y H:i') ?? '—' }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($location->isTentative())
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-700">{{ __('lba.events.tentative') }}</span>
                    @elseif ($location->isActive())
                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-700">{{ __('lba.events.confirmed') }}</span>
                    @endif
                    <a class="principal-button" href="{{ route('clinic.tailadmin.events') }}">{{ __('lba.events.back') }}</a>
                </div>
            </div>

            @if ($location->isTentative())
                <p class="p-4 text-sm text-amber-700">{{ __('lba.events.preregistration') }}</p>
            @else
                <form method="get" action="{{ route('clinic.tailadmin.events.show', $location->getKey()) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.events.date') }}
                        <input class="principal-input" type="date" name="date" value="{{ $date }}">
                    </label>
                    <button class="principal-button">{{ __('lba.events.apply') }}</button>
                </form>

                <div class="border-b border-slate-100 p-4">
                    <h3 class="font-semibold text-navy-900">{{ __('lba.events.schedule') }}</h3>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($slots as $index => $slot)
                        @php($start = \Illuminate\Support\Carbon::parse($slot['starts_at']))
                        @php($end = \Illuminate\Support\Carbon::parse($slot['ends_at']))
                        <li class="flex flex-wrap items-center justify-between gap-3 p-4" data-slot-index="{{ $index }}">
                            <span class="font-medium">{{ $start->format('H:i') }} – {{ $end->format('H:i') }}</span>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm text-slate-600">
                                    {{ __('lba.events.capacity') }}:
                                    {{ $slot['capacity'] === null ? __('lba.events.unlimited') : $slot['remaining'].'/'.$slot['capacity'] }}
                                </span>
                                @if ($slot['available'])
                                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-700">{{ __('lba.events.available') }}</span>
                                @elseif ($slot['capacity'] !== null && $slot['remaining'] === 0)
                                    <span class="rounded-full bg-rose-100 px-3 py-1 text-sm font-medium text-rose-700">{{ __('lba.events.full') }}</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-600">{{ __('lba.events.past') }}</span>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.events.no_slots') }}</li>
                    @endforelse
                </ul>
            @endif
        </section>

        @if ($canBook)
            @php($first = collect($slots)->firstWhere('available', true))
            <section class="principal-card grid gap-4 p-4">
                <div>
                    <h3 class="font-semibold text-navy-900">{{ __('lba.events.book_patient') }}</h3>
                    <p class="text-sm text-slate-600">{{ __('lba.events.booking_hint') }}</p>
                </div>

                @if ($errors->any())
                    <div class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
                @endif

                <form method="post" action="{{ route('clinic.tailadmin.events.bookings.store', $location->getKey()) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.appointments.patient') }}
                        <select class="principal-input" name="patient_id" required>
                            <option value="">—</option>
                            @foreach ($patients as $patient)
                                <option value="{{ $patient->id }}" @selected(old('patient_id') === $patient->id)>{{ $patient->full_name }} · {{ $patient->patient_number }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.appointments.service_type') }}
                        <select class="principal-input" name="service_type" required>
                            @foreach (Modules\Appointments\Models\Appointment::SERVICES as $service)
                                <option value="{{ $service }}" @selected(old('service_type', 'consultation') === $service)>{{ __('lba.appointments.service_'.$service) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.appointments.starts_at') }}
                        <input class="principal-input" type="datetime-local" name="starts_at" required
                            value="{{ is_string(old('starts_at')) ? old('starts_at') : ($first ? \Illuminate\Support\Carbon::parse($first['starts_at'])->format('Y-m-d\TH:i') : '') }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.appointments.ends_at') }}
                        <input class="principal-input" type="datetime-local" name="ends_at" required
                            value="{{ is_string(old('ends_at')) ? old('ends_at') : ($first ? \Illuminate\Support\Carbon::parse($first['ends_at'])->format('Y-m-d\TH:i') : '') }}">
                    </label>
                    <label class="grid gap-1 text-sm sm:col-span-2">
                        {{ __('lba.appointments.notes') }}
                        <textarea class="principal-input" name="notes" rows="3" maxlength="1000">{{ is_string(old('notes')) ? old('notes') : '' }}</textarea>
                    </label>
                    <div class="sm:col-span-2">
                        <button class="principal-button principal-button-primary" @disabled($patients->isEmpty())>{{ __('lba.events.book_patient') }}</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="principal-card">
            <div class="border-b border-slate-200 p-4">
                <h3 class="font-semibold text-navy-900">{{ __('lba.events.bookings') }}</h3>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($bookings as $booking)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-semibold">{{ $booking->patient?->full_name ?? '—' }}</p>
                            <p class="text-sm text-slate-600">
                                {{ $booking->starts_at?->translatedFormat('d M Y H:i') ?? '—' }} → {{ $booking->ends_at?->translatedFormat('d M Y H:i') ?? '—' }}
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
