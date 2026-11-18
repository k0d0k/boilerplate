@php
    use Modules\LBA\Models\Assessment;
    use Modules\LBA\Models\AssessmentItem;

    $assessment = $this->assessment();
    $images = $this->images();
    $humanAnnotations = $this->humanAnnotations();
    $suggestions = $this->aiSuggestions();
    $items = $this->items();
    $evidence = $this->evidenceByIndicator();
    $terminology = $this->terminologySummary();
    $canDecide = $this->canDecide();
    $canApprove = $this->canApprove();
    $canGenerate = $this->canGenerate();
    $versions = $this->reportVersions();
    $history = $this->reportHistory();
@endphp

<x-filament-panels::page>
    <style>
        .lba-rv { --lba-rv-bg:#0c1725; --lba-rv-panel:#0f1d2f; --lba-rv-line:rgba(255,255,255,0.10); --lba-rv-fg:rgba(255,255,255,0.92); --lba-rv-muted:rgba(255,255,255,0.55); --lba-rv-teal:#0aa5a6; --lba-rv-bright:#7fe3e0; background:var(--lba-rv-bg); border:1px solid var(--lba-rv-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-rv-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-rv__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-rv-bright); }
        .lba-rv__meta { display:flex; flex-wrap:wrap; gap:0.75rem 1.5rem; font-size:0.8125rem; color:var(--lba-rv-muted); }
        .lba-rv__meta strong { color:#fff; font-weight:600; }
        .lba-rv__grid { display:grid; gap:0.75rem; grid-template-columns:repeat(2, minmax(0, 1fr)); }
        @media (max-width:64rem) { .lba-rv__grid { grid-template-columns:1fr; } }
        .lba-rv__block { padding:0.875rem; border:1px solid var(--lba-rv-line); border-radius:0.625rem; background:var(--lba-rv-panel); }
        .lba-rv__block-title { font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-rv-muted); margin-bottom:0.625rem; }
        .lba-rv__table { width:100%; border-collapse:collapse; font-size:0.8125rem; }
        .lba-rv__table th { text-align:left; font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--lba-rv-muted); padding:0.375rem 0.5rem; border-bottom:1px solid var(--lba-rv-line); }
        .lba-rv__table td { padding:0.4375rem 0.5rem; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align:top; }
        .lba-rv__thumbs { display:flex; flex-wrap:wrap; gap:0.5rem; }
        .lba-rv__thumb { width:7rem; border:1px solid var(--lba-rv-line); border-radius:0.5rem; overflow:hidden; background:#08111c; }
        .lba-rv__thumb img { display:block; width:100%; height:5rem; object-fit:cover; }
        .lba-rv__badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; }
        .lba-rv__badge--green { background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-rv__badge--yellow { background:rgba(255,209,102,0.18); color:#ffd166; }
        .lba-rv__badge--red { background:rgba(248,113,113,0.18); color:#fca5a5; }
        .lba-rv__badge--gray { background:rgba(255,255,255,0.12); color:var(--lba-rv-muted); }
        .lba-rv__badge--warning { background:rgba(255,209,102,0.18); color:#ffd166; }
        .lba-rv__badge--success { background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-rv__badge--danger { background:rgba(248,113,113,0.18); color:#fca5a5; }
        .lba-rv__row { display:flex; justify-content:space-between; gap:0.5rem; padding:0.3125rem 0; border-bottom:1px solid rgba(255,255,255,0.06); font-size:0.75rem; }
        .lba-rv__textarea { width:100%; min-height:4rem; padding:0.5rem 0.625rem; border:1px solid var(--lba-rv-line); border-radius:0.5rem; background:#08111c; color:#fff; font-size:0.8125rem; }
        .lba-rv__select { padding:0.5rem 0.625rem; border:1px solid var(--lba-rv-line); border-radius:0.5rem; background:#08111c; color:#fff; font-size:0.8125rem; }
        .lba-rv__toolbar { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-end; }
        .lba-rv__field { display:flex; flex-direction:column; gap:0.25rem; font-size:0.75rem; color:var(--lba-rv-muted); }
        .lba-rv__btn { display:inline-flex; align-items:center; gap:0.375rem; padding:0.5rem 0.875rem; border:1px solid var(--lba-rv-line); border-radius:0.625rem; background:rgba(255,255,255,0.04); color:var(--lba-rv-fg); font-size:0.8125rem; font-weight:600; cursor:pointer; text-decoration:none; }
        .lba-rv__btn:hover { background:rgba(255,255,255,0.09); }
        .lba-rv__btn:disabled { opacity:0.5; cursor:default; }
        .lba-rv__btn--primary { border-color:transparent; background:var(--lba-rv-teal); color:#fff; }
        .lba-rv__btn--primary:hover { background:#0b8f90; }
        .lba-rv__btn--danger { border-color:transparent; background:#b3363a; color:#fff; }
        .lba-rv__btn--danger:hover { background:#9c2f33; }
        .lba-rv__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid rgba(232,121,249,0.5); background:rgba(232,121,249,0.10); color:#f0abfc; }
        .lba-rv__notice-neutral { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-rv-line); background:rgba(255,255,255,0.04); color:var(--lba-rv-muted); }
        .lba-rv__empty { font-size:0.8125rem; color:var(--lba-rv-muted); }
    </style>

    <div class="lba-rv">
        <div>
            <p class="lba-rv__eyebrow">{{ __('lba.review.eyebrow') }}</p>
            <div class="lba-rv__meta" style="margin-top:0.5rem;">
                <span>{{ __('lba.exams.patient') }}: <strong>{{ $this->patientName() }}</strong></span>
                <span>{{ __('lba.exams.id') }}: <strong>{{ $assessment?->examination_id ?? '—' }}</strong></span>
                <span>{{ __('lba.exams.branch') }}: <strong>{{ $this->branchName() }}</strong></span>
                <span>{{ __('lba.exams.mode') }}: <strong>{{ $this->modeLabel() }}</strong></span>
                <span>{{ __('lba.assessment.status') }}: <strong>{{ $this->statusLabel() }}</strong></span>
                <span>{{ __('lba.review.brand') }}: <strong>{{ $this->brandName() }}</strong></span>
            </div>
        </div>

        {{-- Terminology gate context --}}
        <div class="lba-rv__notice-neutral">
            {{ __('lba.review.terminology_status', ['reviewed' => $terminology['reviewed'], 'reference' => $terminology['reference_only']]) }}
        </div>
        @if ($terminology['reviewed'] === 0)
            <div class="lba-rv__notice">{{ __('lba.review.reference_only_notice') }}</div>
        @endif

        @if (! $this->hasBrand())
            <div class="lba-rv__notice">{{ __('lba.review.brand_required') }}</div>
        @endif

        {{-- Original microscope images --}}
        <div class="lba-rv__block">
            <p class="lba-rv__block-title">{{ __('lba.review.images') }}</p>
            @if ($images->isEmpty())
                <p class="lba-rv__empty">{{ __('lba.exams.images.empty') }}</p>
            @else
                <div class="lba-rv__thumbs">
                    @foreach ($images as $image)
                        <div class="lba-rv__thumb">
                            <img src="{{ $image->streamUrl() }}" alt="{{ __('lba.exams.workspace.image_alt') }}">
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="lba-rv__grid">
            {{-- Human annotations --}}
            <div class="lba-rv__block">
                <p class="lba-rv__block-title">{{ __('lba.review.human_annotations') }}</p>
                @if ($humanAnnotations->isEmpty())
                    <p class="lba-rv__empty">{{ __('lba.review.no_human_annotations') }}</p>
                @else
                    @foreach ($humanAnnotations as $annotation)
                        <div class="lba-rv__row">
                            <span>{{ $annotation->label ?: $annotation->type }}</span>
                            <span class="lba-rv__badge lba-rv__badge--gray">{{ __('lba.review.provenance_human') }}</span>
                        </div>
                    @endforeach
                @endif
            </div>

            {{-- Unreviewed AI suggestions --}}
            <div class="lba-rv__block">
                <p class="lba-rv__block-title">{{ __('lba.review.ai_suggestions') }}</p>
                @if ($suggestions->isEmpty())
                    <p class="lba-rv__empty">{{ __('lba.exams.ai.no_suggestions') }}</p>
                @else
                    @foreach ($suggestions as $suggestion)
                        <div class="lba-rv__row">
                            <span>{{ $suggestion->label }}</span>
                            <span class="lba-rv__badge lba-rv__badge--warning">{{ $suggestion->confidencePercent() }} · {{ __('lba.review.provenance_ai') }}</span>
                        </div>
                    @endforeach
                    <p class="lba-rv__empty" style="margin-top:0.5rem;">{{ __('lba.exams.ai.disclaimer') }}</p>
                @endif
            </div>
        </div>

        {{-- Observation categories + evidence --}}
        <div class="lba-rv__block">
            <p class="lba-rv__block-title">{{ __('lba.review.observations') }}</p>
            <table class="lba-rv__table">
                <thead>
                    <tr>
                        <th>{{ __('lba.review.col_indicator') }}</th>
                        <th>{{ __('lba.review.col_category') }}</th>
                        <th>{{ __('lba.review.col_terminology') }}</th>
                        <th>{{ __('lba.review.col_evidence') }}</th>
                        <th>{{ __('lba.review.col_notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @php $key = (string) $item->indicator_key; @endphp
                        <tr>
                            <td>
                                {{ $item->displayLabel() }}
                                <div class="lba-rv__empty">{{ $key }}</div>
                            </td>
                            <td>
                                <span class="lba-rv__badge lba-rv__badge--{{ $item->category }}">{{ $this->categoryLabel((string) $item->category) }}</span>
                            </td>
                            <td>
                                @if ($item->isValidated())
                                    <span class="lba-rv__badge lba-rv__badge--success">{{ __('lba.review.terminology_reviewed') }}</span>
                                @else
                                    <span class="lba-rv__badge lba-rv__badge--gray">{{ __('lba.assessment.reference_only') }}</span>
                                @endif
                            </td>
                            <td>
                                @forelse (($evidence[$key] ?? []) as $link)
                                    <div class="lba-rv__empty">{{ __('lba.review.evidence_'.$link['type']) }} · {{ $link['provenance'] }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td class="lba-rv__empty">{{ $item->notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="lba-rv__empty">{{ __('lba.assessment.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Reviewer decision --}}
        @if ($canDecide)
            <div class="lba-rv__block">
                <p class="lba-rv__block-title">{{ __('lba.review.decision') }}</p>
                <label class="lba-rv__field" style="margin-bottom:0.625rem;">
                    {{ __('lba.review.notes') }}
                    <textarea class="lba-rv__textarea" wire:model="notes" placeholder="{{ __('lba.review.notes_placeholder') }}"></textarea>
                </label>
                <div class="lba-rv__toolbar">
                    <label class="lba-rv__field">
                        {{ __('lba.review.report_locale') }}
                        <select class="lba-rv__select" wire:model="locale">
                            @foreach ($this->availableLocales() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="button" class="lba-rv__btn" wire:click="returnForChanges">{{ __('lba.review.return') }}</button>
                    <button type="button" class="lba-rv__btn lba-rv__btn--danger" wire:click="reject">{{ __('lba.review.reject') }}</button>
                    <button type="button" class="lba-rv__btn lba-rv__btn--primary" wire:click="approve" @disabled(! $canApprove)>{{ __('lba.review.approve') }}</button>
                </div>
                @if ($this->isSelfApproval())
                    <div class="lba-rv__notice" style="margin-top:0.625rem;">{{ __('lba.review.self_approval_notice') }}</div>
                @endif
                <p class="lba-rv__empty" style="margin-top:0.5rem;">{{ __('lba.review.decision_note') }}</p>
            </div>
        @endif

        {{-- Reports --}}
        <div class="lba-rv__block">
            <p class="lba-rv__block-title">{{ __('lba.review.reports') }}</p>

            @if ($canGenerate)
                <div class="lba-rv__toolbar" style="margin-bottom:0.625rem;">
                    <button type="button" class="lba-rv__btn lba-rv__btn--primary" wire:click="generateReport">{{ __('lba.review.generate') }}</button>
                </div>
            @endif

            @if ($versions->isEmpty())
                <p class="lba-rv__empty">{{ __('lba.review.no_reports') }}</p>
            @else
                <table class="lba-rv__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.report.report_version') }}</th>
                            <th>{{ __('lba.report.locale') }}</th>
                            <th>{{ __('lba.report.approved_at') }}</th>
                            <th>{{ __('lba.review.release_col') }}</th>
                            <th>{{ __('lba.review.checksum') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($versions as $version)
                            <tr>
                                <td>v{{ $version->version }}</td>
                                <td>{{ $version->locale }}</td>
                                <td>{{ $version->approved_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>
                                    @if ($version->isReleased())
                                        <span class="lba-rv__badge lba-rv__badge--success">{{ __('lba.review.released_badge') }}</span>
                                        <div class="lba-rv__empty">{{ $version->released_at?->format('Y-m-d H:i') }}</div>
                                    @elseif ($this->canRelease($version))
                                        <button type="button" class="lba-rv__btn lba-rv__btn--primary" wire:click="releaseToPatient('{{ $version->getKey() }}')">{{ __('lba.review.release') }}</button>
                                    @else
                                        <span class="lba-rv__badge lba-rv__badge--gray">{{ __('lba.review.not_released') }}</span>
                                    @endif
                                </td>
                                <td class="lba-rv__empty">{{ \Illuminate\Support\Str::limit($version->checksum, 16) }}…</td>
                                <td>
                                    <a class="lba-rv__btn" href="{{ $this->previewUrl($version) }}" target="_blank" rel="noopener">{{ __('lba.review.preview') }}</a>
                                    <a class="lba-rv__btn" href="{{ $this->downloadUrl($version) }}">{{ __('lba.review.download') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="lba-rv__empty" style="margin-top:0.625rem;">{{ __('lba.review.release_notice') }}</p>
            @endif

            @if ($history->isNotEmpty())
                <p class="lba-rv__block-title" style="margin-top:0.875rem;">{{ __('lba.review.history') }}</p>
                @foreach ($history as $event)
                    <div class="lba-rv__row">
                        <span>{{ $this->reportEventLabel((string) $event->action) }}<span class="lba-rv__empty"> · {{ $event->note }}</span></span>
                        <span class="lba-rv__empty">{{ $event->occurred_at?->format('Y-m-d H:i') }}</span>
                    </div>
                @endforeach
            @endif

            <p class="lba-rv__empty" style="margin-top:0.625rem;">{{ __('lba.review.private_notice') }}</p>
        </div>
    </div>
</x-filament-panels::page>
