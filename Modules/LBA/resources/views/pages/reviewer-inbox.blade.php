@php
    use Modules\LBA\Models\Assessment;

    $pending = $this->pending();
    $decided = $this->decided();
@endphp

<x-filament-panels::page>
    <style>
        .lba-review { --lba-review-bg:#0c1725; --lba-review-panel:#0f1d2f; --lba-review-line:rgba(255,255,255,0.10); --lba-review-fg:rgba(255,255,255,0.92); --lba-review-muted:rgba(255,255,255,0.55); --lba-review-teal:#0aa5a6; --lba-review-bright:#7fe3e0; background:var(--lba-review-bg); border:1px solid var(--lba-review-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-review-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-review__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-review-bright); }
        .lba-review__block { padding:0.875rem; border:1px solid var(--lba-review-line); border-radius:0.625rem; background:var(--lba-review-panel); }
        .lba-review__block-title { font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-review-muted); margin-bottom:0.625rem; }
        .lba-review__table { width:100%; border-collapse:collapse; font-size:0.8125rem; }
        .lba-review__table th { text-align:left; font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--lba-review-muted); padding:0.375rem 0.5rem; border-bottom:1px solid var(--lba-review-line); }
        .lba-review__table td { padding:0.5rem; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align:middle; }
        .lba-review__badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; }
        .lba-review__badge--warning { background:rgba(255,209,102,0.18); color:#ffd166; }
        .lba-review__badge--success { background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-review__badge--danger { background:rgba(248,113,113,0.18); color:#fca5a5; }
        .lba-review__badge--gray { background:rgba(255,255,255,0.12); color:var(--lba-review-muted); }
        .lba-review__link { color:var(--lba-review-bright); font-weight:600; text-decoration:none; }
        .lba-review__link:hover { text-decoration:underline; }
        .lba-review__empty { font-size:0.8125rem; color:var(--lba-review-muted); }
        .lba-review__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-review-line); background:rgba(255,255,255,0.04); color:var(--lba-review-muted); }
    </style>

    <div class="lba-review">
        <div>
            <p class="lba-review__eyebrow">{{ __('lba.review.eyebrow') }}</p>
        </div>

        <div class="lba-review__notice">{{ __('lba.review.inbox_notice') }}</div>

        <div class="lba-review__block">
            <p class="lba-review__block-title">{{ __('lba.review.pending_heading') }}</p>
            @if ($pending->isEmpty())
                <p class="lba-review__empty">{{ __('lba.review.pending_empty') }}</p>
            @else
                <table class="lba-review__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.review.col_patient') }}</th>
                            <th>{{ __('lba.review.col_examination') }}</th>
                            <th>{{ __('lba.review.col_branch') }}</th>
                            <th>{{ __('lba.review.col_mode') }}</th>
                            <th>{{ __('lba.review.col_submitted') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pending as $assessment)
                            <tr>
                                <td>{{ $this->patientName($assessment) }}</td>
                                <td>{{ $assessment->examination_id }}</td>
                                <td>{{ $this->branchName($assessment) }}</td>
                                <td>{{ __('lba.exams.mode_'.$assessment->mode) }}</td>
                                <td>{{ $assessment->submitted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>
                                    <a class="lba-review__link" href="{{ \Modules\LBA\Filament\Pages\AssessmentReview::getUrl(['assessment' => $assessment->getKey()]) }}">
                                        {{ __('lba.review.open') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="lba-review__block">
            <p class="lba-review__block-title">{{ __('lba.review.decided_heading') }}</p>
            @if ($decided->isEmpty())
                <p class="lba-review__empty">{{ __('lba.review.decided_empty') }}</p>
            @else
                <table class="lba-review__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.review.col_patient') }}</th>
                            <th>{{ __('lba.review.col_examination') }}</th>
                            <th>{{ __('lba.review.col_branch') }}</th>
                            <th>{{ __('lba.review.col_status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($decided as $assessment)
                            <tr>
                                <td>{{ $this->patientName($assessment) }}</td>
                                <td>{{ $assessment->examination_id }}</td>
                                <td>{{ $this->branchName($assessment) }}</td>
                                <td>
                                    <span class="lba-review__badge lba-review__badge--{{ \Modules\LBA\Models\Assessment::statusColor($assessment->status) }}">
                                        {{ $this->statusLabel($assessment->status) }}
                                    </span>
                                </td>
                                <td>
                                    <a class="lba-review__link" href="{{ \Modules\LBA\Filament\Pages\AssessmentReview::getUrl(['assessment' => $assessment->getKey()]) }}">
                                        {{ __('lba.review.open') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
