@extends('principal.layouts.app', ['title' => __('lba.nav.clinic.settings'), 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4 md:grid-cols-2">
        <section class="principal-card grid content-start gap-2 p-5">
            <h2 class="font-semibold text-navy-900">{{ __('lba.clinic_settings.tenant') }}</h2>
            <p>{{ $tenant?->name ?? '—' }}</p>
        </section>

        <section class="principal-card grid content-start gap-3 p-5">
            <div>
                <h2 class="font-semibold text-navy-900">{{ __('lba.clinic_settings.locations') }}</h2>
                <p class="text-sm text-slate-600">{{ __('lba.clinic_settings.locations_hint') }}</p>
            </div>
            @if ($locationsUrl)
                <a class="principal-button justify-self-start" href="{{ $locationsUrl }}">{{ __('lba.clinic_settings.open') }}</a>
            @else
                <p class="text-sm text-slate-500">{{ __('lba.clinic_settings.unavailable') }}</p>
            @endif
        </section>

        <section class="principal-card grid content-start gap-3 p-5">
            <div>
                <h2 class="font-semibold text-navy-900">{{ __('lba.clinic_settings.team') }}</h2>
                <p class="text-sm text-slate-600">{{ __('lba.clinic_settings.team_hint') }}</p>
            </div>
            @if ($teamUrl)
                <a class="principal-button justify-self-start" href="{{ $teamUrl }}">{{ __('lba.clinic_settings.open') }}</a>
            @else
                <p class="text-sm text-slate-500">{{ __('lba.clinic_settings.unavailable') }}</p>
            @endif
        </section>

        <section class="principal-card grid content-start gap-3 p-5">
            <div>
                <h2 class="font-semibold text-navy-900">{{ __('lba.clinic_settings.brand_report') }}</h2>
                <p class="text-sm text-slate-600">{{ __('lba.clinic_settings.brand_report_hint') }}</p>
            </div>
            @if ($brandReportUrl)
                <a class="principal-button justify-self-start" href="{{ $brandReportUrl }}">{{ __('lba.clinic_settings.open') }}</a>
            @else
                <p class="text-sm text-slate-500">{{ __('lba.clinic_settings.unavailable') }}</p>
            @endif
        </section>
    </div>
@endsection
