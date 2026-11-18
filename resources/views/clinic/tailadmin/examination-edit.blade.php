@extends('principal.layouts.app', ['title' => __('lba.exams.detail.edit_title'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card max-w-3xl p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-navy-900">{{ $examination->patient?->full_name ?? '—' }}</h2>
            <a class="principal-button" href="{{ route('clinic.tailadmin.examinations.show', $examination) }}">{{ __('lba.exams.detail.back') }}</a>
        </div>
        <form method="post" action="{{ route('clinic.tailadmin.examinations.update', $examination) }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            @method('put')
            <label class="grid gap-1 text-sm">
                {{ __('lba.exams.branch') }}
                <select class="principal-input" name="branch_id" required>
                    <option value="">{{ __('lba.branch_switcher.choose') }}</option>
                    @foreach ($branchOptions as $option)
                        <option value="{{ $option->id }}" @selected(old('branch_id', $examination->branch_id) === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.exams.operator') }}
                <select class="principal-input" name="operator_user_id">
                    <option value="">{{ __('lba.exams.detail.operator_unassigned') }}</option>
                    @foreach ($operatorOptions as $id => $name)
                        <option value="{{ $id }}" @selected(old('operator_user_id', $examination->operator_user_id) === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.exams.mode') }}
                <select class="principal-input" name="mode" required>
                    @foreach (Modules\LBA\Models\Examination::MODES as $mode)
                        <option value="{{ $mode }}" @selected(old('mode', $examination->mode) === $mode)>{{ __('lba.exams.mode_'.$mode) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.exams.captured_at') }}
                <input class="principal-input" type="datetime-local" name="captured_at"
                    value="{{ old('captured_at', $examination->captured_at?->format('Y-m-d\TH:i')) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.exams.instrument') }}
                <input class="principal-input" name="instrument" maxlength="120" value="{{ old('instrument', $examination->instrument) }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.exams.magnification') }}
                <input class="principal-input" name="magnification" maxlength="60" value="{{ old('magnification', $examination->magnification) }}">
            </label>
            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __('lba.exams.notes') }}
                <textarea class="principal-input" name="notes" rows="3" maxlength="2000">{{ old('notes', $examination->notes) }}</textarea>
            </label>
            <label class="flex items-start gap-2 text-sm sm:col-span-2">
                <input class="mt-1" type="checkbox" name="record_consent" value="1" @checked(old('record_consent') === '1')>
                <span>{{ __('lba.exams.record_consent') }}<span class="block text-slate-500">{{ __('lba.exams.record_consent_hint') }}</span></span>
            </label>
            <div class="flex flex-wrap gap-2 sm:col-span-2">
                <button class="principal-button principal-button-primary">{{ __('principal.brands.save') }}</button>
                <a class="principal-button" href="{{ route('clinic.tailadmin.examinations.show', $examination) }}">{{ __('principal.brands.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection
