@extends('principal.layouts.app', [
    'title' => $location ? __('lba.branches.edit') : __('lba.locations.add'),
    'panel' => 'clinic',
])

@section('content')
    @php($action = $location ? route('clinic.tailadmin.locations.update', $location) : route('clinic.tailadmin.locations.store'))

    <form method="post" action="{{ $action }}" class="grid gap-5">
        @csrf
        @if ($location)
            @method('put')
        @endif

        <section class="principal-card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="font-semibold text-navy-900 sm:col-span-2">{{ __('lba.branches.section_identity') }}</h2>

            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.name') }}
                <input class="principal-input" name="name" maxlength="255" required value="{{ old('name', $location?->name) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.code') }}
                <input class="principal-input" name="code" maxlength="64" required value="{{ old('code', $location?->code) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.status') }}
                <select name="status" class="principal-input" required>
                    @foreach (\App\Platform\Branches\Models\TenantBranch::STATUSES as $status)
                        <option value="{{ $status }}" @selected(old('status', $location?->status ?? 'active') === $status)>{{ __('lba.branches.status_'.$status) }}</option>
                    @endforeach
                </select>
            </label>
        </section>

        <section class="principal-card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="font-semibold text-navy-900 sm:col-span-2">{{ __('lba.branches.section_location') }}</h2>

            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __('lba.branches.address') }}
                <input class="principal-input" name="address" maxlength="255" value="{{ old('address', $location?->address) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.city') }}
                <input class="principal-input" name="city" maxlength="255" value="{{ old('city', $location?->city) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.region') }}
                <input class="principal-input" name="region" maxlength="255" value="{{ old('region', $location?->region) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.country') }}
                <input class="principal-input" name="country" maxlength="2" value="{{ old('country', $location?->country ?? 'ID') }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.timezone') }}
                <input class="principal-input" name="timezone" maxlength="64" value="{{ old('timezone', $location?->timezone ?? 'Asia/Jakarta') }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.phone') }}
                <input class="principal-input" type="tel" name="phone" maxlength="32" value="{{ old('phone', $location?->phone) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.email') }}
                <input class="principal-input" type="email" name="email" maxlength="255" value="{{ old('email', $location?->email) }}">
            </label>
        </section>

        <section class="principal-card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="font-semibold text-navy-900 sm:col-span-2">{{ __('lba.branches.section_scheduling') }}</h2>

            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.location_type') }}
                <select name="location_type" class="principal-input" required data-location-type>
                    @foreach (\App\Platform\Branches\Models\TenantBranch::LOCATION_TYPES as $type)
                        <option value="{{ $type }}" @selected(old('location_type', $location?->location_type ?? 'permanent') === $type)>{{ __('lba.branches.location_type_'.$type) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.slot_minutes') }}
                <input class="principal-input" type="number" name="slot_minutes" min="5" max="240"
                    value="{{ old('slot_minutes', $location?->slot_minutes ?? \App\Platform\Branches\Models\TenantBranch::DEFAULT_SLOT_MINUTES) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.opens_at') }}
                <input class="principal-input" type="time" name="opens_at" value="{{ old('opens_at', $location?->opens_at) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.closes_at') }}
                <input class="principal-input" type="time" name="closes_at" value="{{ old('closes_at', $location?->closes_at) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.branches.capacity') }}
                <input class="principal-input" type="number" name="capacity" min="1" value="{{ old('capacity', $location?->capacity) }}">
                <span class="text-xs text-slate-500">{{ __('lba.branches.capacity_hint') }}</span>
            </label>

            <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2" data-location-window>
                <p class="text-xs text-slate-500 sm:col-span-2">{{ __('lba.branches.operates_hint') }}</p>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.branches.operates_from') }}
                    <input class="principal-input" type="datetime-local" name="operates_from"
                        value="{{ old('operates_from', $location?->operates_from?->format('Y-m-d\TH:i')) }}">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.branches.operates_until') }}
                    <input class="principal-input" type="datetime-local" name="operates_until"
                        value="{{ old('operates_until', $location?->operates_until?->format('Y-m-d\TH:i')) }}">
                </label>
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <button class="principal-button principal-button-primary" type="submit">{{ __('principal.brands.save') }}</button>
            <a class="principal-button" href="{{ route('clinic.tailadmin.locations') }}">{{ __('principal.brands.cancel') }}</a>
        </div>
    </form>

    <script>
        (function () {
            const type = document.querySelector('[data-location-type]');
            const window = document.querySelector('[data-location-window]');
            if (!type || !window) return;
            const sync = () => { window.hidden = !['temporary', 'event'].includes(type.value); };
            type.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
