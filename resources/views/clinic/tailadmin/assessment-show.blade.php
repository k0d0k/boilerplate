@extends('principal.layouts.app', ['title' => __('lba.assessment.title'), 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a class="principal-button" href="{{ route('clinic.tailadmin.examinations.show', $examination) }}">{{ __('lba.assessment.back') }}</a>
            <span class="principal-card px-4 py-2 text-sm font-medium">
                {{ __('lba.assessment.status') }}:
                {{ $assessment ? __('lba.assessment.status_'.$assessment->status) : '—' }}
            </span>
        </div>

        @if (session('success'))
            <p class="principal-card border-brand-200 p-4 text-sm text-brand-700">{{ session('success') }}</p>
        @endif

        @if ($errors->any())
            <p class="principal-card border-red-200 p-4 text-sm text-red-600">{{ $errors->first() }}</p>
        @endif

        <section class="principal-card p-5">
            <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.assessment.title') }}</h2>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.patient') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination->patient?->full_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.branch') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination->branch?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.assessment.mode') }}</dt>
                    <dd class="font-medium">{{ __('lba.exams.mode_'.$examination->mode) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.assessment.last_saved') }}</dt>
                    <dd class="font-medium">{{ $assessment?->last_saved_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-slate-500">{{ __('lba.assessment.category_notice') }}</p>
            <p class="mt-2 text-sm text-slate-500">{{ __('lba.assessment.reference_notice') }}</p>
        </section>

        @if ($assessment === null)
            <section class="principal-card p-5">
                <p class="text-sm text-slate-600">{{ __('lba.assessment.no_assessment') }}</p>
                <p class="mt-2 text-sm text-slate-500">{{ __('lba.assessment.no_assessment_hint') }}</p>
            </section>
        @else
            @unless ($canRecord)
                <section class="principal-card p-5">
                    <p class="text-sm text-slate-600">{{ __('lba.assessment.submitted_locked') }}</p>
                </section>
            @endunless

            @if ($canRecord)
                <section class="principal-card p-5">
                    <div class="grid gap-5 lg:grid-cols-2">
                        <form method="post" action="{{ route('clinic.tailadmin.assessments.phase', $examination) }}" class="grid content-start gap-2">
                            @csrf
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.assessment.program_phase') }}
                                <select class="principal-input" name="program_phase">
                                    @foreach ($programPhaseOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($assessment->program_phase === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <p class="text-sm text-slate-500">{{ __('lba.assessment.program_note') }}</p>
                            <button class="principal-button justify-self-start">{{ __('lba.assessment.save_draft') }}</button>
                        </form>

                        <form method="post" action="{{ route('clinic.tailadmin.assessments.brand', $examination) }}" class="grid content-start gap-2">
                            @csrf
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.assessment.brand') }}
                                <select class="principal-input" name="brand_id" required>
                                    <option value="">{{ __('lba.assessment.brand_none') }}</option>
                                    @foreach ($brandOptions as $id => $name)
                                        <option value="{{ $id }}" @selected((string) $assessment->brand_id === $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="principal-button justify-self-start">{{ __('lba.assessment.save_draft') }}</button>
                        </form>
                    </div>
                </section>
            @endif

            <section class="principal-card p-5">
                <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.assessment.summary_notes') }}</h2>
                @if ($canRecord)
                    <form method="post" action="{{ route('clinic.tailadmin.assessments.draft', $examination) }}" class="grid gap-3">
                        @csrf
                        <textarea class="principal-input" name="notes" rows="4" maxlength="5000">{{ old('notes', $assessment->notes) }}</textarea>
                        <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.assessment.save_draft') }}</button>
                    </form>
                @else
                    <p class="whitespace-pre-line text-sm text-slate-600">{{ $assessment->notes ?: '—' }}</p>
                @endif
            </section>

            <section class="principal-card p-5">
                <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.assessment.nav') }}</h2>
                <ul class="grid gap-3">
                    @forelse ($items as $item)
                        <li class="grid gap-3 rounded-lg border border-slate-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium text-navy-900">{{ $item->displayLabel() }}</span>
                                <span class="flex flex-wrap items-center gap-2 text-sm">
                                    <span class="principal-card px-3 py-1">{{ __('lba.assessment.category_'.$item->category) }}</span>
                                    @if ($item->isReferenceOnly())
                                        <span class="text-slate-500">{{ __('lba.assessment.reference_only') }}</span>
                                    @endif
                                    @if (($evidenceCounts[$item->indicator_key] ?? 0) > 0)
                                        <span class="text-slate-500">{{ __('lba.assessment.evidence') }}: {{ $evidenceCounts[$item->indicator_key] }}</span>
                                    @endif
                                </span>
                            </div>

                            @if ($canRecord)
                                <div class="flex flex-wrap gap-2">
                                    @foreach ([
                                        \Modules\LBA\Models\AssessmentItem::CATEGORY_GREEN,
                                        \Modules\LBA\Models\AssessmentItem::CATEGORY_YELLOW,
                                        \Modules\LBA\Models\AssessmentItem::CATEGORY_RED,
                                        \Modules\LBA\Models\AssessmentItem::CATEGORY_UNASSESSED,
                                    ] as $category)
                                        <form method="post" action="{{ route('clinic.tailadmin.assessments.category', $examination) }}">
                                            @csrf
                                            <input type="hidden" name="indicator_key" value="{{ $item->indicator_key }}">
                                            <input type="hidden" name="category" value="{{ $category }}">
                                            <button class="principal-button @if ($item->category === $category) principal-button-primary @endif">
                                                {{ __('lba.assessment.category_'.$category) }}
                                            </button>
                                        </form>
                                    @endforeach
                                </div>

                                <form method="post" action="{{ route('clinic.tailadmin.assessments.notes', $examination) }}" class="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
                                    @csrf
                                    <input type="hidden" name="indicator_key" value="{{ $item->indicator_key }}">
                                    <label class="grid gap-1 text-sm">
                                        {{ __('lba.assessment.item_notes') }}
                                        <textarea class="principal-input" name="notes" rows="2" maxlength="2000">{{ $item->notes }}</textarea>
                                    </label>
                                    <button class="principal-button justify-self-start">{{ __('lba.assessment.save_notes') }}</button>
                                </form>

                                @if ($images->isNotEmpty())
                                    <form method="post" action="{{ route('clinic.tailadmin.assessments.evidence', $examination) }}" class="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
                                        @csrf
                                        <input type="hidden" name="indicator_key" value="{{ $item->indicator_key }}">
                                        <label class="grid gap-1 text-sm">
                                            {{ __('lba.assessment.attach_image') }}
                                            <select class="principal-input" name="image_id" required>
                                                @foreach ($images as $image)
                                                    <option value="{{ $image->id }}">{{ $image->id }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <button class="principal-button justify-self-start">{{ __('lba.assessment.attach_image') }}</button>
                                    </form>
                                @endif
                            @elseif ($item->notes)
                                <p class="whitespace-pre-line text-sm text-slate-600">{{ $item->notes }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">{{ __('lba.assessment.empty') }}</li>
                    @endforelse
                </ul>
                <p class="mt-4 text-sm text-slate-500">{{ __('lba.assessment.metrics_note') }}</p>
            </section>

            @if ($canSubmit)
                <section class="principal-card p-5">
                    <form method="post" action="{{ route('clinic.tailadmin.assessments.submit', $examination) }}">
                        @csrf
                        <button class="principal-button principal-button-primary">{{ __('lba.assessment.submit_review') }}</button>
                    </form>
                </section>
            @endif
        @endif

        @if ($aiSuggestions->isNotEmpty())
            <section class="principal-card p-5">
                <h2 class="mb-2 font-semibold text-navy-900">{{ __('lba.review.ai_suggestions') }}</h2>
                <p class="mb-4 text-sm text-slate-500">{{ __('lba.assessment.ai_no_classification') }}</p>
                <ul class="divide-y divide-slate-100">
                    @foreach ($aiSuggestions as $suggestion)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span>{{ $suggestion->label }}</span>
                            <span class="text-slate-500">{{ $suggestion->confidencePercent() }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
