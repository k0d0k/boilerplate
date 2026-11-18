@extends('principal.layouts.app', ['title' => __('lba.review.title'), 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a class="principal-button" href="{{ route('clinic.tailadmin.reviews.index') }}">{{ __('lba.review.back') }}</a>
            <a class="principal-button" href="{{ \Modules\LBA\Filament\Pages\MicroscopeWorkspace::getUrl(['examination' => $examination], panel: 'clinic') }}">{{ __('lba.exams.open_workspace') }}</a>
        </div>

        @if (session('success'))
            <p class="principal-card border-brand-200 p-4 text-sm text-brand-700">{{ session('success') }}</p>
        @endif

        @if ($errors->any())
            <p class="principal-card border-red-200 p-4 text-sm text-red-600">{{ $errors->first() }}</p>
        @endif

        <section class="principal-card p-5">
            <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.review.title') }}</h2>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.patient') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination?->patient?->full_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.exams.branch') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $examination?->branch?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.assessment.status') }}</dt>
                    <dd class="font-medium">{{ __('lba.assessment.status_'.$assessment->status) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.review.brand') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $brandName }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-slate-500">{{ __('lba.review.terminology_status', ['reviewed' => $items->where('terminology_status', \Modules\LBA\Models\AssessmentItem::TERMINOLOGY_APPROVED)->count(), 'reference' => $items->count()]) }}</p>
            @if ($assessment->brand_id === null)
                <p class="mt-2 text-sm text-red-600">{{ __('lba.review.brand_required') }}</p>
            @endif
        </section>

        <section class="principal-card p-5">
            <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.review.observations') }}</h2>
            <ul class="grid gap-3">
                @forelse ($items as $item)
                    <li class="grid gap-2 rounded-lg border border-slate-200 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium text-navy-900">{{ $item->displayLabel() }}</span>
                            <span class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="principal-card px-3 py-1">{{ __('lba.assessment.category_'.$item->category) }}</span>
                                @if ($item->isReferenceOnly())
                                    <span class="text-slate-500">{{ __('lba.assessment.reference_only') }}</span>
                                @endif
                            </span>
                        </div>
                        @if ($item->notes)
                            <p class="whitespace-pre-line text-sm text-slate-600">{{ $item->notes }}</p>
                        @endif
                        @if (! empty($evidenceByIndicator[$item->indicator_key]))
                            <p class="text-sm text-slate-500">
                                {{ __('lba.review.col_evidence') }}:
                                @foreach ($evidenceByIndicator[$item->indicator_key] as $evidence)
                                    {{ __('lba.review.evidence_'.$evidence['type']) }} ({{ __('lba.review.provenance_'.$evidence['provenance']) }})
                                @endforeach
                            </p>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-slate-500">{{ __('lba.assessment.empty') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card p-5">
            <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.review.images') }}</h2>
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($images as $image)
                    <li class="grid gap-2 rounded-lg border border-slate-200 p-3">
                        <img class="w-full rounded" src="{{ $image->streamUrl() }}" alt="{{ __('lba.exams.workspace.image_alt') }}" loading="lazy">
                        <p class="text-xs text-slate-500">{{ $image->id }}</p>
                    </li>
                @empty
                    <li class="text-sm text-slate-500">{{ __('lba.exams.images.empty') }}</li>
                @endforelse
            </ul>
        </section>

        @if ($aiSuggestions->isNotEmpty())
            <section class="principal-card p-5">
                <h2 class="mb-2 font-semibold text-navy-900">{{ __('lba.review.ai_suggestions') }}</h2>
                <p class="mb-4 text-sm text-slate-500">{{ __('lba.assessment.ai_no_classification') }}</p>
                <ul class="divide-y divide-slate-100">
                    @foreach ($aiSuggestions as $suggestion)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span>{{ $suggestion->label }}</span>
                            <span class="text-slate-500">{{ __('lba.review.provenance_ai') }} · {{ $suggestion->confidencePercent() }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($canDecide)
            <section class="principal-card p-5">
                <h2 class="mb-2 font-semibold text-navy-900">{{ __('lba.review.decision') }}</h2>
                @if ($selfApproval)
                    <p class="mb-3 text-sm text-red-600">{{ __('lba.review.self_approval_notice') }}</p>
                @endif
                <form method="post" action="{{ route('clinic.tailadmin.reviews.decide', $assessment) }}" class="grid gap-4">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.review.notes') }}
                        <textarea class="principal-input" name="notes" rows="3" maxlength="2000" placeholder="{{ __('lba.review.notes_placeholder') }}"></textarea>
                    </label>
                    <label class="grid gap-1 text-sm sm:max-w-xs">
                        {{ __('lba.review.report_locale') }}
                        <select class="principal-input" name="locale">
                            @foreach ($localeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <button name="decision" value="return" class="principal-button">{{ __('lba.review.return') }}</button>
                        <button name="decision" value="reject" class="principal-button">{{ __('lba.review.reject') }}</button>
                        @if ($canApprove)
                            <button name="decision" value="approve" class="principal-button principal-button-primary">{{ __('lba.review.approve') }}</button>
                        @endif
                    </div>
                </form>
                <p class="mt-3 text-sm text-slate-500">{{ __('lba.review.decision_note') }}</p>
            </section>
        @endif

        <section class="principal-card p-5">
            <h2 class="mb-2 font-semibold text-navy-900">{{ __('lba.review.reports') }}</h2>
            <p class="mb-4 text-sm text-slate-500">{{ __('lba.review.private_notice') }}</p>
            <ul class="divide-y divide-slate-100">
                @forelse ($reportVersions as $version)
                    <li class="grid gap-2 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">
                                {{ __('lba.review.reports') }} v{{ $version->version }} ·
                                {{ strtoupper($version->locale ?? '—') }} ·
                                {{ $version->generated_at?->translatedFormat('d M Y H:i') ?? '—' }}
                            </span>
                            <span class="text-sm">
                                @if ($version->isReleased())
                                    <span class="principal-card px-3 py-1 text-brand-700">{{ __('lba.review.released_badge') }}</span>
                                @else
                                    <span class="principal-card px-3 py-1 text-slate-500">{{ __('lba.review.not_released') }}</span>
                                @endif
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a class="principal-button" href="{{ route('lba.reports.preview', $version) }}">{{ __('lba.review.preview') }}</a>
                            <a class="principal-button" href="{{ route('lba.reports.download', $version) }}">{{ __('lba.review.download') }}</a>
                            @if ($canRelease && ! $version->isReleased())
                                <form method="post" action="{{ route('clinic.tailadmin.reviews.release', [$assessment, $version]) }}">
                                    @csrf
                                    <button class="principal-button principal-button-primary">{{ __('lba.review.release') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="py-4 text-sm text-slate-500">{{ __('lba.review.no_reports') }}</li>
                @endforelse
            </ul>
            <p class="mt-4 text-sm text-slate-500">{{ __('lba.review.release_notice') }}</p>
        </section>

        <section class="principal-card p-5">
            <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.review.history') }}</h2>
            <ul class="divide-y divide-slate-100">
                @forelse ($reportEvents as $event)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span>
                            {{ __('lba.review.event_'.$event->action) }}
                            @if ($event->note) · {{ $event->note }} @endif
                        </span>
                        <span class="text-slate-500">{{ $event->occurred_at?->translatedFormat('d M Y H:i') ?? '—' }}</span>
                    </li>
                @empty
                    <li class="py-4 text-sm text-slate-500">—</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
