@extends('principal.layouts.app', ['title' => $title, 'panel' => 'clinic'])

@section('content')
    @php($booking = $intake === 'appointments')
    @php($catalog = $booking ? 'lba.appointments.' : 'lba.exams.')
    <section class="principal-card grid max-w-3xl gap-5 p-5">
        <form method="get" action="{{ route('clinic.tailadmin.intake.create', $intake) }}" class="flex flex-wrap items-end gap-2">
            <label class="grid flex-1 gap-1 text-sm">
                {{ __('principal.clinic.search') }}
                <input class="principal-input" type="search" name="search" value="{{ $search }}" maxlength="255">
            </label>
            <button class="principal-button">{{ __('principal.brands.apply') }}</button>
        </form>
        <form method="post" action="{{ route('clinic.tailadmin.intake.store', $intake) }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="tenant_id" value="{{ $tenantId }}">
            <label class="grid gap-1 text-sm">
                {{ __($catalog.'patient') }}
                <select class="principal-input" name="patient_id" required>
                    <option value="">—</option>
                    @foreach ($patients as $patient)
                        <option value="{{ $patient->id }}" @selected(old('patient_id') === $patient->id)>{{ $patient->full_name }} · {{ $patient->patient_number }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __($catalog.'branch') }}
                <select class="principal-input" name="branch_id" required>
                    <option value="">{{ __('lba.branch_switcher.choose') }}</option>
                    @foreach ($branchOptions as $option)
                        <option value="{{ $option->id }}" @selected(old('branch_id', $branchId) === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
            @if ($booking)
                <label class="grid gap-1 text-sm">
                    {{ __($catalog.'service_type') }}
                    <select class="principal-input" name="service_type" required>
                        @foreach (Modules\Appointments\Models\Appointment::SERVICES as $service)
                            <option value="{{ $service }}" @selected(old('service_type', 'consultation') === $service)>{{ __($catalog.'service_'.$service) }}</option>
                        @endforeach
                    </select>
                </label>
                @foreach (['starts_at', 'ends_at'] as $field)
                    <label class="grid gap-1 text-sm">
                        {{ __($catalog.$field) }}
                        <input class="principal-input" type="datetime-local" name="{{ $field }}" value="{{ is_string(old($field)) ? old($field) : '' }}" required>
                    </label>
                @endforeach
            @else
                <label class="grid gap-1 text-sm">
                    {{ __($catalog.'mode') }}
                    <select class="principal-input" name="mode" required>
                        @foreach (Modules\LBA\Models\Examination::MODES as $mode)
                            <option value="{{ $mode }}" @selected(old('mode', 'manual') === $mode)>{{ __($catalog.'mode_'.$mode) }}</option>
                        @endforeach
                    </select>
                </label>
                @foreach (['instrument' => 120, 'magnification' => 60] as $field => $max)
                    <label class="grid gap-1 text-sm">
                        {{ __($catalog.$field) }}
                        <input class="principal-input" name="{{ $field }}" maxlength="{{ $max }}" value="{{ is_string(old($field)) ? old($field) : '' }}">
                    </label>
                @endforeach
                <label class="flex items-start gap-2 text-sm sm:col-span-2">
                    <input class="mt-1" type="checkbox" name="record_consent" value="1" @checked(old('record_consent') === '1')>
                    <span>{{ __($catalog.'record_consent') }}<span class="block text-slate-500">{{ __($catalog.'record_consent_hint') }}</span></span>
                </label>
            @endif
            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __($catalog.'notes') }}
                <textarea class="principal-input" name="notes" rows="3" maxlength="{{ $booking ? 1000 : 2000 }}">{{ is_string(old('notes')) ? old('notes') : '' }}</textarea>
            </label>
            <div class="flex flex-wrap gap-2 sm:col-span-2">
                <button class="principal-button principal-button-primary" @disabled($branchOptions === [] || $patients->isEmpty())>{{ __('principal.brands.save') }}</button>
                <a class="principal-button" href="{{ route('clinic.tailadmin.index', $intake) }}">{{ __('principal.brands.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection
