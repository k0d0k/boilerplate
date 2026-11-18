@extends('principal.layouts.app', ['title' => __('lba.practitioners.edit'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card max-w-3xl p-5">
        <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.practitioners.section_identity') }}</h2>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('clinic.tailadmin.practitioners.update', $practitioner) }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            @method('put')
            <label class="grid gap-1 text-sm">
                {{ __('lba.practitioners.display_name') }}
                <input class="principal-input" name="display_name" maxlength="255" required
                    value="{{ is_string(old('display_name')) ? old('display_name') : $practitioner->display_name }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.practitioners.title') }}
                <input class="principal-input" name="title" maxlength="128"
                    value="{{ is_string(old('title')) ? old('title') : $practitioner->title }}">
            </label>
            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __('lba.practitioners.professional_body') }}
                <input class="principal-input" name="professional_body" maxlength="255"
                    value="{{ is_string(old('professional_body')) ? old('professional_body') : $practitioner->professional_body }}">
            </label>
            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __('lba.practitioners.specialities') }}
                <input class="principal-input" name="specialities" maxlength="500"
                    value="{{ is_string(old('specialities')) ? old('specialities') : implode(', ', $practitioner->specialities ?? []) }}">
                <span class="text-xs text-slate-500">{{ __('lba.practitioners.specialities_hint') }}</span>
            </label>
            <label class="grid gap-1 text-sm sm:col-span-2">
                {{ __('lba.practitioners.notes') }}
                <textarea class="principal-input" name="notes" rows="3" maxlength="2000">{{ is_string(old('notes')) ? old('notes') : $practitioner->notes }}</textarea>
            </label>
            <div class="flex flex-wrap gap-2 sm:col-span-2">
                <button class="principal-button principal-button-primary">{{ __('principal.brands.save') }}</button>
                <a class="principal-button" href="{{ route('clinic.tailadmin.practitioners.show', $practitioner) }}">{{ __('principal.brands.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection
