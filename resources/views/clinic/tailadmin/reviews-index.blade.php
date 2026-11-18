@extends('principal.layouts.app', ['title' => __('lba.review.inbox_title'), 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        <section class="principal-card p-5">
            <h2 class="font-semibold text-navy-900">{{ __('lba.review.inbox_title') }}</h2>
            <p class="mt-2 text-sm text-slate-500">{{ __('lba.review.inbox_notice') }}</p>
        </section>

        <section class="principal-card">
            <h3 class="border-b border-slate-200 p-4 font-semibold text-navy-900">{{ __('lba.review.pending_heading') }}</h3>
            <ul class="divide-y divide-slate-100">
                @forelse ($pending as $assessment)
                    @php($examination = $assessment->examination)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-semibold">{{ $examination?->patient?->full_name ?? '—' }}</p>
                            <p class="text-sm text-slate-600">
                                {{ $examination?->branch?->name ?? '—' }} ·
                                {{ __('lba.assessment.mode') }}: {{ __('lba.exams.mode_'.($examination?->mode ?? 'manual')) }} ·
                                {{ $assessment->submitted_at?->translatedFormat('d M Y H:i') ?? '—' }}
                            </p>
                        </div>
                        <a class="principal-button" href="{{ route('clinic.tailadmin.reviews.show', $assessment) }}">{{ __('lba.review.open') }}</a>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.review.pending_empty') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card">
            <h3 class="border-b border-slate-200 p-4 font-semibold text-navy-900">{{ __('lba.review.decided_heading') }}</h3>
            <ul class="divide-y divide-slate-100">
                @forelse ($decided as $assessment)
                    @php($examination = $assessment->examination)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-semibold">{{ $examination?->patient?->full_name ?? '—' }}</p>
                            <p class="text-sm text-slate-600">
                                {{ $examination?->branch?->name ?? '—' }} ·
                                {{ __('lba.assessment.status_'.$assessment->status) }}
                            </p>
                        </div>
                        <a class="principal-button" href="{{ route('clinic.tailadmin.reviews.show', $assessment) }}">{{ __('lba.review.open') }}</a>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.review.decided_empty') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
