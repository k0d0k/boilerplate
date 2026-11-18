@php
    use Modules\LBA\Models\Assessment;
    use Modules\LBA\Models\AssessmentItem;

    $examination = $this->examination();
    $assessment = $this->assessment();
    $hasAssessment = $assessment !== null;
    $items = $this->filteredItems();
    $counts = $this->selectedCounts();
    $unassessed = $this->unassessedCount();
    $images = $this->images();
    $suggestions = $this->aiSuggestions();
    $evidenceCounts = $this->evidenceCounts();
    $brandOptions = $this->brandOptions();
    $canEdit = $this->canEdit();
    $canSubmit = $this->canSubmitForReview();
@endphp

<x-filament-panels::page>
    <style>
        .lba-assess {
            --lba-assess-bg: #0c1725;
            --lba-assess-panel: #0f1d2f;
            --lba-assess-line: rgba(255, 255, 255, 0.10);
            --lba-assess-fg: rgba(255, 255, 255, 0.92);
            --lba-assess-muted: rgba(255, 255, 255, 0.55);
            --lba-assess-teal: #0aa5a6;
            --lba-assess-bright: #7fe3e0;
            background: var(--lba-assess-bg);
            border: 1px solid var(--lba-assess-line);
            border-radius: 0.75rem;
            padding: 1.25rem;
            color: var(--lba-assess-fg);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .lba-assess__eyebrow { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: var(--lba-assess-bright); }
        .lba-assess__meta { display: flex; flex-wrap: wrap; gap: 0.75rem 1.5rem; font-size: 0.8125rem; color: var(--lba-assess-muted); }
        .lba-assess__meta strong { color: #fff; font-weight: 600; }
        .lba-assess__metrics { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); }
        .lba-assess__metric { padding: 0.75rem 1rem; border: 1px solid var(--lba-assess-line); border-radius: 0.625rem; background: var(--lba-assess-panel); }
        .lba-assess__metric-label { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--lba-assess-muted); }
        .lba-assess__metric-value { margin-top: 0.25rem; font-size: 1.5rem; font-weight: 700; color: #fff; line-height: 1.1; }
        .lba-assess__metric--green .lba-assess__metric-value { color: #86efac; }
        .lba-assess__metric--yellow .lba-assess__metric-value { color: #ffd166; }
        .lba-assess__metric--red .lba-assess__metric-value { color: #fca5a5; }
        .lba-assess__metric--unassessed .lba-assess__metric-value { color: var(--lba-assess-bright); }
        .lba-assess__block { padding: 0.875rem; border: 1px solid var(--lba-assess-line); border-radius: 0.625rem; background: var(--lba-assess-panel); }
        .lba-assess__block-title { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--lba-assess-muted); margin-bottom: 0.625rem; }
        .lba-assess__notice { padding: 0.625rem 0.75rem; border-radius: 0.5rem; font-size: 0.75rem; border: 1px solid rgba(232, 121, 249, 0.5); background: rgba(232, 121, 249, 0.10); color: #f0abfc; }
        .lba-assess__thumbs { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .lba-assess__thumb { width: 5.5rem; padding: 0; border: 2px solid transparent; border-radius: 0.5rem; background: #08111c; overflow: hidden; cursor: pointer; }
        .lba-assess__thumb.is-active { border-color: var(--lba-assess-bright); }
        .lba-assess__thumb img { display: block; width: 100%; height: 4rem; object-fit: cover; }
        .lba-assess__suggestions { display: flex; flex-direction: column; gap: 0.375rem; }
        .lba-assess__suggestion { display: flex; justify-content: space-between; gap: 0.5rem; padding: 0.375rem 0.5rem; border: 1px dashed rgba(232, 121, 249, 0.5); border-radius: 0.5rem; background: rgba(232, 121, 249, 0.06); font-size: 0.75rem; color: var(--lba-assess-fg); }
        .lba-assess__toolbar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; }
        .lba-assess__field { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.75rem; color: var(--lba-assess-muted); }
        .lba-assess__input, .lba-assess__select, .lba-assess__textarea { padding: 0.5rem 0.625rem; border: 1px solid var(--lba-assess-line); border-radius: 0.5rem; background: #08111c; color: #fff; font-size: 0.8125rem; }
        .lba-assess__textarea { width: 100%; min-height: 3.5rem; }
        .lba-assess__grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (max-width: 64rem) { .lba-assess__grid { grid-template-columns: 1fr; } }
        .lba-assess__row { padding: 0.75rem; border: 1px solid var(--lba-assess-line); border-radius: 0.625rem; background: rgba(255, 255, 255, 0.03); display: flex; flex-direction: column; gap: 0.5rem; }
        .lba-assess__row-head { display: flex; justify-content: space-between; gap: 0.5rem; align-items: flex-start; }
        .lba-assess__label { font-size: 0.8125rem; font-weight: 600; color: var(--lba-assess-fg); }
        .lba-assess__key { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.625rem; color: var(--lba-assess-muted); }
        .lba-assess__selectors { display: flex; gap: 0.375rem; }
        .lba-assess__opt { flex: 1; padding: 0.375rem 0.25rem; border: 1px solid var(--lba-assess-line); border-radius: 0.5rem; background: rgba(255, 255, 255, 0.03); color: var(--lba-assess-muted); font-size: 0.6875rem; font-weight: 600; cursor: pointer; }
        .lba-assess__opt:disabled { opacity: 0.5; cursor: default; }
        .lba-assess__opt--green.is-active { border-color: #86efac; background: rgba(74, 222, 128, 0.18); color: #86efac; }
        .lba-assess__opt--yellow.is-active { border-color: #ffd166; background: rgba(255, 209, 102, 0.18); color: #ffd166; }
        .lba-assess__opt--red.is-active { border-color: #fca5a5; background: rgba(248, 113, 113, 0.18); color: #fca5a5; }
        .lba-assess__row-foot { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .lba-assess__badge { display: inline-flex; align-items: center; padding: 0.0625rem 0.4rem; border-radius: 999px; font-size: 0.625rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; }
        .lba-assess__badge--green { background: rgba(74, 222, 128, 0.18); color: #86efac; }
        .lba-assess__badge--yellow { background: rgba(255, 209, 102, 0.18); color: #ffd166; }
        .lba-assess__badge--red { background: rgba(248, 113, 113, 0.18); color: #fca5a5; }
        .lba-assess__badge--unassessed { background: rgba(255, 255, 255, 0.12); color: var(--lba-assess-muted); }
        .lba-assess__btn { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 0.875rem; border: 1px solid var(--lba-assess-line); border-radius: 0.625rem; background: rgba(255, 255, 255, 0.04); color: var(--lba-assess-fg); font-size: 0.8125rem; font-weight: 600; cursor: pointer; }
        .lba-assess__btn:hover { background: rgba(255, 255, 255, 0.09); }
        .lba-assess__btn--primary { border-color: transparent; background: var(--lba-assess-teal); color: #fff; }
        .lba-assess__btn--primary:hover { background: #0b8f90; }
        .lba-assess__btn--sm { padding: 0.3125rem 0.625rem; font-size: 0.75rem; }
        .lba-assess__empty { font-size: 0.8125rem; color: var(--lba-assess-muted); }
    </style>

    <div class="lba-assess">
        {{-- Header: references, mode, last saved --}}
        <div>
            <p class="lba-assess__eyebrow">{{ __('lba.assessment.eyebrow') }}</p>
            <div class="lba-assess__meta" style="margin-top: 0.5rem;">
                <span>{{ __('lba.exams.patient') }}: <strong>{{ $this->patientName() }}</strong></span>
                <span>{{ __('lba.assessment.examination') }}: <strong>{{ $examination->getKey() }}</strong></span>
                <span>{{ __('lba.exams.branch') }}: <strong>{{ $this->branchName() }}</strong></span>
                @if ($this->operatorName())
                    <span>{{ __('lba.exams.operator') }}: <strong>{{ $this->operatorName() }}</strong></span>
                @endif
                <span>{{ __('lba.assessment.mode') }}: <strong>{{ $this->modeLabel() }}</strong></span>
                <span>{{ __('lba.assessment.status') }}: <strong>{{ $hasAssessment ? __('lba.assessment.status_'.$assessment->status) : '—' }}</strong></span>
                <span>{{ __('lba.assessment.last_saved') }}: <strong>{{ $this->lastSavedAt() ?? '—' }}</strong></span>
            </div>
        </div>

        @if ($hasAssessment)
            {{-- Category counts + unassessed. These are INTERNAL review-priority
                 tallies over reference-only legacy labels — never validated
                 disease-risk results. --}}
            <div class="lba-assess__metrics">
                <div class="lba-assess__metric lba-assess__metric--green">
                    <p class="lba-assess__metric-label">{{ __('lba.assessment.category_green') }}</p>
                    <p class="lba-assess__metric-value">{{ $counts['green'] }}</p>
                </div>
                <div class="lba-assess__metric lba-assess__metric--yellow">
                    <p class="lba-assess__metric-label">{{ __('lba.assessment.category_yellow') }}</p>
                    <p class="lba-assess__metric-value">{{ $counts['yellow'] }}</p>
                </div>
                <div class="lba-assess__metric lba-assess__metric--red">
                    <p class="lba-assess__metric-label">{{ __('lba.assessment.category_red') }}</p>
                    <p class="lba-assess__metric-value">{{ $counts['red'] }}</p>
                </div>
                <div class="lba-assess__metric lba-assess__metric--unassessed">
                    <p class="lba-assess__metric-label">{{ __('lba.assessment.category_unassessed') }}</p>
                    <p class="lba-assess__metric-value">{{ $unassessed }}</p>
                </div>
            </div>

            <p class="lba-assess__empty">{{ __('lba.assessment.metrics_note') }}</p>

            <div class="lba-assess__notice">{{ __('lba.assessment.category_notice') }}</div>
            <div class="lba-assess__notice">{{ __('lba.assessment.reference_notice') }}</div>
        @endif

        {{-- Microscope image thumbnails --}}
        <div class="lba-assess__block">
            <p class="lba-assess__block-title">{{ __('lba.assessment.images_title') }}</p>
            @if ($images->isEmpty())
                <p class="lba-assess__empty">{{ __('lba.exams.images.empty') }}</p>
            @else
                <div class="lba-assess__thumbs">
                    @foreach ($images as $image)
                        <button type="button"
                                class="lba-assess__thumb {{ $selectedImageId === (string) $image->getKey() ? 'is-active' : '' }}"
                                wire:click="selectImage('{{ $image->getKey() }}')"
                                title="{{ __('lba.assessment.select_image') }}">
                            <img src="{{ $image->streamUrl() }}" alt="{{ __('lba.exams.workspace.image_alt') }}">
                        </button>
                    @endforeach
                </div>
                <p class="lba-assess__empty" style="margin-top: 0.5rem;">{{ __('lba.assessment.image_attach_hint') }}</p>
            @endif
        </div>

        {{-- AI suggestions: unreviewed information only --}}
        @if ($suggestions->isNotEmpty())
            <div class="lba-assess__block">
                <p class="lba-assess__block-title">{{ __('lba.exams.ai.heading') }}</p>
                <div class="lba-assess__suggestions">
                    @foreach ($suggestions as $suggestion)
                        <div class="lba-assess__suggestion">
                            <span>{{ $suggestion->label }}</span>
                            <span>{{ $suggestion->confidencePercent() }} · {{ $suggestion->provenance }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="lba-assess__notice" style="margin-top: 0.5rem;">{{ __('lba.exams.ai.disclaimer') }}</p>
                <p class="lba-assess__notice" style="margin-top: 0.375rem;">{{ __('lba.assessment.ai_no_classification') }}</p>
            </div>
        @endif

        @if ($hasAssessment)
        {{-- Program phase, brand, assessment notes --}}
        <div class="lba-assess__block">
            <div class="lba-assess__toolbar">
                <label class="lba-assess__field">
                    {{ __('lba.assessment.program_phase') }}
                    <select class="lba-assess__select" wire:model.live="programPhase" @disabled(! $canEdit)>
                        @foreach ($this->programPhaseOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lba-assess__field">
                    {{ __('lba.assessment.brand') }}
                    <select class="lba-assess__select" wire:model.live="brandId" @disabled(! $canEdit)>
                        <option value="">{{ __('lba.assessment.brand_none') }}</option>
                        @foreach ($brandOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="lba-assess__empty" style="margin-top: 0.5rem;">{{ __('lba.assessment.program_note') }}</p>
        </div>

        <div class="lba-assess__block">
            <p class="lba-assess__block-title">{{ __('lba.assessment.summary_notes') }}</p>
            <textarea class="lba-assess__textarea" wire:model="notes" @disabled(! $canEdit)></textarea>
        </div>

        {{-- Search --}}
        <div class="lba-assess__toolbar">
            <label class="lba-assess__field" style="flex: 1; min-width: 12rem;">
                {{ __('lba.assessment.search') }}
                <input type="text" class="lba-assess__input" wire:model.live.debounce.300ms="search" placeholder="{{ __('lba.assessment.search_placeholder') }}">
            </label>
        </div>

        {{-- Indicator rows: two-column on desktop, one on mobile --}}
        <div class="lba-assess__grid">
            @forelse ($items as $item)
                @php
                    $key = (string) $item->indicator_key;
                    $category = $this->categories[$key] ?? AssessmentItem::CATEGORY_UNASSESSED;
                    $evidenceCount = $evidenceCounts[$key] ?? 0;
                @endphp
                <div class="lba-assess__row">
                    <div class="lba-assess__row-head">
                        <div>
                            <p class="lba-assess__label">{{ $item->displayLabel() }}</p>
                            <p class="lba-assess__key">{{ $key }} · v{{ $item->catalog_version }}</p>
                            @if ($item->isReferenceOnly())
                                <span class="lba-assess__badge lba-assess__badge--unassessed" style="margin-top: 0.25rem;">{{ __('lba.assessment.reference_only') }}</span>
                            @endif
                        </div>
                        <span class="lba-assess__badge lba-assess__badge--{{ $category }}">{{ $this->categoryLabel($category) }}</span>
                    </div>

                    <div class="lba-assess__selectors">
                        <button type="button"
                                class="lba-assess__opt lba-assess__opt--green {{ $category === AssessmentItem::CATEGORY_GREEN ? 'is-active' : '' }}"
                                wire:click="setCategory('{{ $key }}', '{{ AssessmentItem::CATEGORY_GREEN }}')"
                                @disabled(! $canEdit)>{{ __('lba.assessment.category_green') }}</button>
                        <button type="button"
                                class="lba-assess__opt lba-assess__opt--yellow {{ $category === AssessmentItem::CATEGORY_YELLOW ? 'is-active' : '' }}"
                                wire:click="setCategory('{{ $key }}', '{{ AssessmentItem::CATEGORY_YELLOW }}')"
                                @disabled(! $canEdit)>{{ __('lba.assessment.category_yellow') }}</button>
                        <button type="button"
                                class="lba-assess__opt lba-assess__opt--red {{ $category === AssessmentItem::CATEGORY_RED ? 'is-active' : '' }}"
                                wire:click="setCategory('{{ $key }}', '{{ AssessmentItem::CATEGORY_RED }}')"
                                @disabled(! $canEdit)>{{ __('lba.assessment.category_red') }}</button>
                    </div>

                    <textarea class="lba-assess__textarea" wire:model="itemNotes.{{ $key }}" placeholder="{{ __('lba.assessment.item_notes') }}" @disabled(! $canEdit)></textarea>

                    <div class="lba-assess__row-foot">
                        <button type="button" class="lba-assess__btn lba-assess__btn--sm" wire:click="saveItemNotes('{{ $key }}')" @disabled(! $canEdit)>
                            {{ __('lba.assessment.save_notes') }}
                        </button>
                        <button type="button" class="lba-assess__btn lba-assess__btn--sm" wire:click="attachImage('{{ $key }}')" @disabled(! $canEdit)>
                            {{ __('lba.assessment.attach_image') }}
                        </button>
                        @if ($evidenceCount > 0)
                            <span class="lba-assess__badge lba-assess__badge--unassessed">{{ __('lba.assessment.evidence') }}: {{ $evidenceCount }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="lba-assess__empty">{{ __('lba.assessment.empty') }}</p>
            @endforelse
        </div>

        {{-- Actions --}}
        @if ($canEdit)
            <div class="lba-assess__toolbar">
                <button type="button" class="lba-assess__btn" wire:click="saveDraft">
                    {{ __('lba.assessment.save_draft') }}
                </button>
                <button type="button" class="lba-assess__btn lba-assess__btn--primary" wire:click="submitForReview" @disabled(! $canSubmit)>
                    {{ __('lba.assessment.submit_review') }}
                </button>
            </div>
        @else
            <div class="lba-assess__notice" style="border-color: var(--lba-assess-line); background: rgba(255,255,255,0.04); color: var(--lba-assess-muted);">
                {{ __('lba.assessment.submitted_locked') }}
            </div>
        @endif
        @else
            {{-- Read-only identity, or no assessment recorded yet: no clinical
                 row was created and no catalog was provisioned for this view. --}}
            <div class="lba-assess__notice" style="border-color: var(--lba-assess-line); background: rgba(255,255,255,0.04); color: var(--lba-assess-muted);">
                {{ __('lba.assessment.no_assessment') }}
            </div>
            <p class="lba-assess__empty">{{ __('lba.assessment.no_assessment_hint') }}</p>
        @endif
    </div>
</x-filament-panels::page>
