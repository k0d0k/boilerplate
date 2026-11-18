@extends('principal.layouts.app', ['title' => __('lba.consultations.create'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card grid max-w-3xl gap-5 p-5">
        <div class="border-b border-slate-200 pb-3">
            <h2 class="font-semibold text-navy-900">{{ __('lba.consultations.create') }}</h2>
            <p class="text-sm text-slate-600">{{ __('lba.consultations.empty_description') }}</p>
        </div>

        @if ($errors->any())
            <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('clinic.tailadmin.consultations.store') }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            <label class="grid gap-1 text-sm">
                {{ __('lba.consultations.patient') }}
                <select class="principal-input" name="patient_id" required>
                    <option value="">—</option>
                    @foreach ($patients as $patient)
                        <option value="{{ $patient->id }}" @selected(old('patient_id') === $patient->id)>{{ $patient->full_name }} · {{ $patient->patient_number }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.consultations.branch') }}
                <select class="principal-input" name="branch_id" required>
                    <option value="">{{ __('lba.branch_switcher.choose') }}</option>
                    @foreach ($branchOptions as $option)
                        <option value="{{ $option->id }}" @selected(old('branch_id', $branchId) === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.consultations.staff') }}
                <select class="principal-input" name="staff_user_id">
                    <option value="">{{ __('lba.consultations.staff_unassigned') }}</option>
                    @foreach ($staffOptions as $id => $name)
                        <option value="{{ $id }}" @selected((string) old('staff_user_id') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.consultations.channel') }}
                <select class="principal-input" name="channel" required>
                    @foreach (Modules\Consultations\Models\Consultation::CHANNELS as $channel)
                        <option value="{{ $channel }}" @selected(old('channel', 'in_person') === $channel)>{{ __('lba.consultations.channel_'.$channel) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.consultations.type') }}
                <select class="principal-input" name="type" required>
                    @foreach (Modules\Consultations\Models\Consultation::TYPES as $type)
                        <option value="{{ $type }}" @selected(old('type', 'consultation') === $type)>{{ __('lba.consultations.type_'.$type) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.consultations.scheduled_at') }}
                <input class="principal-input" type="datetime-local" name="scheduled_at" value="{{ is_string(old('scheduled_at')) ? old('scheduled_at') : '' }}">
            </label>
            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __('lba.consultations.reason') }}
                <textarea class="principal-input" name="reason" rows="2" maxlength="1000">{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
            </label>
            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __('lba.consultations.summary') }}
                <textarea class="principal-input" name="summary" rows="3" maxlength="2000">{{ is_string(old('summary')) ? old('summary') : '' }}</textarea>
            </label>
            <div class="flex flex-wrap gap-2 sm:col-span-2">
                <button class="principal-button principal-button-primary" @disabled($branchOptions === [] || $patients->isEmpty())>{{ __('principal.brands.save') }}</button>
                <a class="principal-button" href="{{ route('clinic.tailadmin.index', 'consultations') }}">{{ __('principal.brands.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection
