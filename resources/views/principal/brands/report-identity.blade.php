@extends('principal.layouts.app', ['title' => __('lba.brands.section_report')])

@section('content')
    <section class="principal-card max-w-3xl p-5 sm:p-6">
        <h2 class="wrap-break-word text-lg font-semibold text-navy-900">{{ $brand->name }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('lba.brands.report_notice') }}</p>
        <form method="post" action="{{ route('principal.tailadmin.brands.report-identity.update', $brand) }}" class="mt-5 grid gap-5 sm:grid-cols-2">
            @csrf
            @method('PATCH')
            @foreach ([...$textFields, 'report_website'] as $field)
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('lba.brands.'.$field) }}
                    <input class="principal-input" type="{{ $field === 'report_website' ? 'url' : 'text' }}" name="{{ $field }}"
                        value="{{ is_string(old($field, $brand->{$field})) ? old($field, $brand->{$field}) : '' }}"
                        maxlength="255" @error($field) aria-invalid="true" @enderror>
                </label>
            @endforeach
            <label class="grid gap-1.5 text-sm font-medium">
                {{ __('lba.brands.report_language') }}
                <select name="report_language" class="principal-input" required @error('report_language') aria-invalid="true" @enderror>
                    @foreach ($languages as $language)
                        <option value="{{ $language }}" @selected(old('report_language', $brand->reportLanguage()) === $language)>
                            {{ config('localization.labels.'.$language, $language) }}
                        </option>
                    @endforeach
                </select>
            </label>
            @if ($brand->hasReportLogo())
                <p class="self-end pb-2 text-sm text-slate-600">{{ __('lba.brand_report.logo_present') }}</p>
            @endif
            @foreach ($longTextFields as $field)
                <label class="grid gap-1.5 text-sm font-medium sm:col-span-2">
                    {{ __('lba.brands.'.$field) }}
                    <textarea class="principal-input" name="{{ $field }}" rows="3" maxlength="16000"
                        @error($field) aria-invalid="true" @enderror>{{ is_string(old($field, $brand->{$field})) ? old($field, $brand->{$field}) : '' }}</textarea>
                </label>
            @endforeach
            <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-5 sm:col-span-2">
                <button class="principal-button principal-button-primary" type="submit">{{ __('lba.brand_report.save') }}</button>
                <a href="{{ route('principal.tailadmin.brands.index') }}" class="principal-button">{{ __('principal.brands.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection
