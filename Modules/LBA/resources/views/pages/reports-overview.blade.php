@php
    $reports = $this->reports();
    $grouped = $reports->groupBy('assessment_id');
@endphp

<x-filament-panels::page>
    <style>
        .lba-reports { --lba-reports-bg:#0c1725; --lba-reports-panel:#0f1d2f; --lba-reports-line:rgba(255,255,255,0.10); --lba-reports-fg:rgba(255,255,255,0.92); --lba-reports-muted:rgba(255,255,255,0.55); --lba-reports-bright:#7fe3e0; background:var(--lba-reports-bg); border:1px solid var(--lba-reports-line); border-radius:0.75rem; padding:1.25rem; color:var(--lba-reports-fg); display:flex; flex-direction:column; gap:1rem; }
        .lba-reports__eyebrow { font-size:0.6875rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; color:var(--lba-reports-bright); }
        .lba-reports__block { padding:0.875rem; border:1px solid var(--lba-reports-line); border-radius:0.625rem; background:var(--lba-reports-panel); }
        .lba-reports__block-title { font-size:0.6875rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--lba-reports-muted); margin-bottom:0.625rem; }
        .lba-reports__meta { font-size:0.75rem; color:var(--lba-reports-muted); margin-bottom:0.5rem; }
        .lba-reports__table { width:100%; border-collapse:collapse; font-size:0.8125rem; }
        .lba-reports__table th { text-align:left; font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--lba-reports-muted); padding:0.375rem 0.5rem; border-bottom:1px solid var(--lba-reports-line); }
        .lba-reports__table td { padding:0.5rem; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align:middle; }
        .lba-reports__badge { display:inline-flex; align-items:center; padding:0.0625rem 0.45rem; border-radius:999px; font-size:0.625rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; background:rgba(74,222,128,0.18); color:#86efac; }
        .lba-reports__link { color:var(--lba-reports-bright); font-weight:600; text-decoration:none; margin-right:0.75rem; }
        .lba-reports__link:hover { text-decoration:underline; }
        .lba-reports__empty { font-size:0.8125rem; color:var(--lba-reports-muted); }
        .lba-reports__notice { padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; border:1px solid var(--lba-reports-line); background:rgba(255,255,255,0.04); color:var(--lba-reports-muted); }
    </style>

    <div class="lba-reports">
        <div>
            <p class="lba-reports__eyebrow">{{ __('lba.reports.eyebrow') }}</p>
        </div>

        <div class="lba-reports__notice">{{ __('lba.reports.notice') }}</div>

        <div class="lba-reports__block">
            <p class="lba-reports__block-title">{{ __('lba.reports.list_heading') }}</p>

            @if ($reports->isEmpty())
                <p class="lba-reports__empty">{{ __('lba.reports.empty') }}</p>
            @else
                @foreach ($grouped as $versions)
                    @php $latest = $versions->first(); @endphp
                    <div style="margin-bottom:1rem;">
                        <p class="lba-reports__meta">
                            {{ $this->patientName($latest) }}
                            · {{ __('lba.reports.col_examination') }}: {{ $this->examinationId($latest) }}
                            · {{ $this->branchName($latest) }}
                        </p>
                        <table class="lba-reports__table">
                            <thead>
                                <tr>
                                    <th>{{ __('lba.reports.col_version') }}</th>
                                    <th>{{ __('lba.reports.col_locale') }}</th>
                                    <th>{{ __('lba.reports.col_generated') }}</th>
                                    <th>{{ __('lba.reports.col_size') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($versions as $version)
                                    <tr>
                                        <td><span class="lba-reports__badge">v{{ $version->version }}</span></td>
                                        <td>{{ strtoupper((string) $version->locale) }}</td>
                                        <td>{{ $version->generated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                        <td>{{ $this->humanSize($version->size_bytes) }}</td>
                                        <td>
                                            <a class="lba-reports__link" href="{{ $this->previewUrl($version) }}" target="_blank" rel="noopener">
                                                {{ __('lba.review.preview') }}
                                            </a>
                                            <a class="lba-reports__link" href="{{ $this->downloadUrl($version) }}">
                                                {{ __('lba.review.download') }}
                                            </a>
                                            @if ($reviewUrl = $this->reviewUrl($version))
                                                <a class="lba-reports__link" href="{{ $reviewUrl }}">
                                                    {{ __('lba.review.title') }}
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</x-filament-panels::page>
