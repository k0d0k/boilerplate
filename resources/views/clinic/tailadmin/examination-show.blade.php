@extends('principal.layouts.app', ['title' => __('lba.exams.detail.title'), 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a class="principal-button" href="{{ route('clinic.tailadmin.index', 'examinations') }}">{{ __('lba.exams.detail.back') }}</a>
            <div class="flex flex-wrap gap-2">
                @if ($canAssess)
                    <a class="principal-button principal-button-primary" href="{{ route('clinic.tailadmin.assessments.show', $examination) }}">{{ __('lba.assessment.open') }}</a>
                @endif
                @if ($canEdit)
                    <a class="principal-button" href="{{ route('clinic.tailadmin.examinations.edit', $examination) }}">{{ __('lba.exams.edit') }}</a>
                @endif
                <a class="principal-button" href="{{ \Modules\LBA\Filament\Pages\MicroscopeWorkspace::getUrl(['examination' => $examination], panel: 'clinic') }}">{{ __('lba.exams.open_workspace') }}</a>
            </div>
        </div>

        <section class="principal-card p-5">
            <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.exams.detail.overview') }}</h2>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.id') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination->id }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.patient') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination->patient?->full_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.branch') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination->branch?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.appointment') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination->appointment?->starts_at?->translatedFormat('d M Y H:i') ?? __('lba.exams.appointment_none') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.operator') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination->operator_user_id ? ($operatorName ?? $examination->operator_user_id) : __('lba.exams.detail.operator_unassigned') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.mode') }}</dt>
                    <dd class="font-medium">{{ __('lba.exams.mode_'.$examination->mode) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.status') }}</dt>
                    <dd class="font-medium">{{ __('lba.exams.status_'.$examination->status) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.captured_at') }}</dt>
                    <dd class="font-medium">{{ $examination->captured_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.instrument') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination->instrument ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.magnification') }}</dt>
                    <dd class="font-medium">{{ $examination->magnification ?: '—' }}</dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.notes') }}</dt>
                    <dd class="wrap-break-word whitespace-pre-line font-medium">{{ $examination->notes ?: '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="principal-card p-5">
            <h2 class="mb-2 font-semibold text-navy-900">{{ __('lba.exams.detail.consent') }}</h2>
            <p class="text-sm {{ $consentGranted ? 'text-brand-700' : 'text-slate-600' }}">
                {{ $consentGranted ? __('lba.exams.detail.consent_granted') : __('lba.exams.detail.consent_missing') }}
            </p>
            <p class="mt-2 text-sm text-slate-500">{{ __('lba.exams.consent_notice') }}</p>
        </section>

        @if ($canSubmitReview || $canCancel)
            <section class="principal-card p-5">
                <h2 class="mb-3 font-semibold text-navy-900">{{ __('lba.exams.detail.actions') }}</h2>
                <div class="flex flex-wrap gap-2">
                    @if ($canSubmitReview)
                        <form method="post" action="{{ route('clinic.tailadmin.examinations.transition', $examination) }}">
                            @csrf
                            <input type="hidden" name="to" value="{{ \Modules\LBA\Models\Examination::STATUS_REVIEW_REQUIRED }}">
                            <button class="principal-button principal-button-primary">{{ __('lba.exams.action_submit_review') }}</button>
                        </form>
                    @endif
                    @if ($canCancel)
                        <form method="post" action="{{ route('clinic.tailadmin.examinations.transition', $examination) }}">
                            @csrf
                            <input type="hidden" name="to" value="{{ \Modules\LBA\Models\Examination::STATUS_CANCELLED }}">
                            <button class="principal-button">{{ __('lba.exams.action_cancel') }}</button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        <section class="principal-card p-5">
            <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.exams.images.title') }}</h2>
            @if ($canCapture)
                <div class="mb-5 grid gap-5 lg:grid-cols-2">
                    <form method="post" action="{{ route('clinic.tailadmin.examinations.images.store', $examination) }}" enctype="multipart/form-data" class="grid content-start gap-3">
                        @csrf
                        <h3 class="font-medium text-navy-900">{{ __('lba.exams.detail.upload') }}</h3>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.exams.detail.upload_field') }}
                            <input class="principal-input" type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.exams.instrument') }}
                            <input class="principal-input" type="text" name="instrument_label" maxlength="120" value="{{ $examination->instrument }}">
                        </label>
                        <p class="text-sm text-slate-500">{{ __('lba.exams.upload_hint') }}</p>
                        <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.exams.detail.upload_action') }}</button>
                    </form>

                    <div data-lba-capture
                        data-store-url="{{ route('clinic.tailadmin.examinations.images.store', $examination) }}"
                        data-error="{{ __('lba.exams.detail.capture_unavailable') }}"
                        class="grid content-start gap-3">
                        <h3 class="font-medium text-navy-900">{{ __('lba.exams.detail.capture') }}</h3>
                        <input type="hidden" name="_token" value="{{ csrf_token() }}" data-lba-capture-token>
                        <video data-lba-capture-video class="w-full rounded-lg border border-slate-200 bg-slate-950" playsinline muted></video>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="principal-button" data-lba-capture-start>{{ __('lba.exams.workspace.capture_start') }}</button>
                            <button type="button" class="principal-button principal-button-primary" data-lba-capture-still hidden>{{ __('lba.exams.workspace.capture_still') }}</button>
                            <button type="button" class="principal-button" data-lba-capture-stop hidden>{{ __('lba.exams.workspace.capture_stop') }}</button>
                        </div>
                        <p class="text-sm text-slate-500" role="status" aria-live="polite" data-lba-capture-status>{{ __('lba.exams.workspace.capture_hint', ['kb' => $uploadLimitKb]) }}</p>
                    </div>
                </div>
            @endif

            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($images as $image)
                    <li class="grid gap-2 rounded-lg border border-slate-200 p-3">
                        <img class="w-full rounded" src="{{ $image->streamUrl() }}" alt="{{ __('lba.exams.workspace.image_alt') }}" loading="lazy">
                        <div class="text-sm text-slate-600">
                            <p>{{ __('lba.exams.workspace.source') }}: {{ __('lba.exams.workspace.source_'.$image->source) }}</p>
                            <p>{{ __('lba.exams.images.dimensions') }}: {{ $image->width }}×{{ $image->height }}</p>
                            <p>{{ __('lba.exams.images.size') }}: {{ number_format($image->size_bytes / 1024, 0) }} KB</p>
                            <p>{{ __('lba.exams.images.uploaded_at') }}: {{ $image->captureMoment()?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                        </div>
                        <p class="text-xs text-slate-500">{{ __('lba.exams.images.retention_locked') }}</p>
                        @if ($canStartAi)
                            <form method="post" action="{{ route('clinic.tailadmin.examinations.analysis.start', [$examination, $image]) }}">
                                @csrf
                                <button class="principal-button principal-button-primary w-full justify-center">{{ __('lba.exams.ai.start') }}</button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-slate-500">{{ __('lba.exams.images.empty') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card p-5">
            <h2 class="mb-2 font-semibold text-navy-900">{{ __('lba.exams.ai.heading') }}</h2>
            <p class="text-sm text-slate-500">{{ __('lba.exams.ai.start_hint') }}</p>

            @unless ($aiEnabled)
                <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">{{ __('lba.exams.ai.disabled_notice') }}</p>
            @elseif (! $examination->isAiCapable())
                <p class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">{{ __('lba.exams.ai.manual_note') }}</p>
            @endif

            <h3 class="mt-5 font-medium text-navy-900">{{ __('lba.exams.ai.jobs_title') }}</h3>
            <ul class="mt-2 divide-y divide-slate-100">
                @forelse ($analysisJobs as $job)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium">{{ __('lba.exams.ai.state_'.$job->processingState()) }}</p>
                            <p class="wrap-break-word text-slate-600">
                                {{ $job->provider }} · {{ $job->model_name }} {{ $job->model_version }}
                                · {{ __('lba.exams.ai.attempts') }}: {{ $job->attempts }}
                            </p>
                            @if ($job->error_code)
                                <p class="text-red-600">{{ __('lba.exams.ai.error') }}: {{ __('lba.exams.ai.error_'.$job->error_code) }}</p>
                            @endif
                            <p class="text-xs text-slate-500">{{ $job->completed_at?->translatedFormat('d M Y H:i') ?? $job->created_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                        </div>
                        @if ($canRetryAi && $job->isRetryable())
                            <form method="post" action="{{ route('clinic.tailadmin.examinations.analysis.retry', [$examination, $job]) }}">
                                @csrf
                                <button class="principal-button">{{ __('lba.exams.ai.retry') }}</button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-slate-500">{{ __('lba.exams.ai.jobs_empty') }}</li>
                @endforelse
            </ul>

            <h3 class="mt-5 font-medium text-navy-900">{{ __('lba.exams.ai.suggestions_title') }}</h3>
            <p class="mt-1 text-sm text-slate-500">{{ __('lba.exams.ai.disclaimer') }}</p>
            <ul class="mt-2 divide-y divide-slate-100">
                @forelse ($suggestions as $suggestion)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span class="min-w-0 wrap-break-word font-medium">{{ $suggestion->label ?? '—' }}</span>
                        <span class="text-slate-600">
                            {{ __('lba.exams.annotations.type_'.$suggestion->geometryType()) }}
                            · {{ __('lba.exams.ai.suggestion_confidence') }}: {{ $suggestion->confidencePercent() }}
                            @if ($suggestion->isSynthetic()) · {{ __('lba.exams.ai.synthetic_tag') }} @endif
                        </span>
                    </li>
                @empty
                    <li class="py-2 text-sm text-slate-500">{{ __('lba.exams.ai.no_suggestions') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card p-5">
            <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.exams.events.title') }}</h2>
            <ul class="divide-y divide-slate-100">
                @forelse ($events as $event)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span>
                            {{ $event->from_status ? __('lba.exams.status_'.$event->from_status) : '—' }}
                            → {{ __('lba.exams.status_'.$event->to_status) }}
                            @if ($event->note) · {{ $event->note }} @endif
                        </span>
                        <span class="text-slate-500">
                            {{ $actorNames[$event->actor_id] ?? ($event->actor_id ?? '—') }}
                            · {{ $event->occurred_at?->translatedFormat('d M Y H:i') ?? '—' }}
                        </span>
                    </li>
                @empty
                    <li class="text-sm text-slate-500">{{ __('lba.exams.events.empty') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
