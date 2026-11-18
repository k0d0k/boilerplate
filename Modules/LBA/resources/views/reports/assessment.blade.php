@php
    /** @var array<string, mixed> $brand */
    /** @var array<string, mixed> $branch */
    /** @var array<string, mixed> $reviewer */
    /** @var array<string, mixed> $patient */
    /** @var array<string, mixed> $examination */
    /** @var array<string, mixed> $summary */
    $categoryLabels = [
        'green' => __('lba.assessment.category_green'),
        'yellow' => __('lba.assessment.category_yellow'),
        'red' => __('lba.assessment.category_red'),
        'unassessed' => __('lba.assessment.category_unassessed'),
    ];
    $evidenceLabels = [
        'image' => __('lba.report.evidence_image'),
        'annotation' => __('lba.report.evidence_annotation'),
        'detection' => __('lba.report.evidence_detection'),
    ];
    $provenanceLabels = [
        'human' => __('lba.report.provenance_human'),
        'ai' => __('lba.report.provenance_ai'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('lba.report.title') }}</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1b2430; margin: 0; }
        .muted { color: #5b6470; }
        .header { border-bottom: 2px solid #087f80; padding-bottom: 12px; }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-logo { width: 96px; }
        .brand-logo img { max-width: 96px; max-height: 64px; }
        .brand-name { font-size: 18px; font-weight: bold; color: #0b3b3c; }
        .brand-line { font-size: 9.5px; color: #5b6470; line-height: 1.5; }
        .doc-title { margin-top: 14px; font-size: 14px; font-weight: bold; color: #0b3b3c; }
        .doc-subtitle { font-size: 9.5px; color: #5b6470; }
        .section { margin-top: 16px; }
        .section-title { font-size: 10px; font-weight: bold; letter-spacing: 0.08em; text-transform: uppercase; color: #087f80; border-bottom: 1px solid #cfd8dc; padding-bottom: 4px; margin-bottom: 8px; }
        table.meta { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 4px 6px; vertical-align: top; border-bottom: 1px solid #eef1f3; }
        table.meta td.k { width: 34%; color: #5b6470; }
        table.meta td.v { font-weight: bold; color: #1b2430; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #5b6470; border-bottom: 1px solid #cfd8dc; padding: 5px 6px; }
        table.data td { padding: 5px 6px; border-bottom: 1px solid #eef1f3; vertical-align: top; }
        .badge { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8.5px; font-weight: bold; color: #fff; }
        .notice { padding: 8px 10px; border: 1px solid #e0b84d; background: #fdf6e3; color: #6b5410; border-radius: 4px; line-height: 1.5; }
        .notice-neutral { padding: 8px 10px; border: 1px solid #cfd8dc; background: #f4f7f8; color: #3b4550; border-radius: 4px; line-height: 1.5; }
        .limitations { margin-top: 16px; border: 1px solid #cfd8dc; background: #f4f7f8; border-radius: 4px; padding: 10px 12px; }
        .limitations p { margin: 0 0 5px; line-height: 1.55; }
        .footer { margin-top: 18px; border-top: 1px solid #cfd8dc; padding-top: 8px; font-size: 8.5px; color: #5b6470; line-height: 1.5; }
    </style>
</head>
<body>

{{-- Official brand header --}}
<div class="header">
    <table>
        <tr>
            @if (! empty($brand['logo_data']))
                <td class="brand-logo"><img src="{{ $brand['logo_data'] }}" alt=""></td>
            @endif
            <td>
                <div class="brand-name">{{ $brand['display_name'] ?? $brand['name'] ?? 'LBA Cloud' }}</div>
                @if (! empty($brand['address']))
                    <div class="brand-line">{{ $brand['address'] }}</div>
                @endif
                @if (! empty($brand['contact']))
                    <div class="brand-line">{{ $brand['contact'] }}</div>
                @endif
                @if (! empty($brand['website']))
                    <div class="brand-line">{{ $brand['website'] }}</div>
                @endif
            </td>
        </tr>
    </table>
    @if (! empty($brand['header']))
        <div class="brand-line" style="margin-top: 8px;">{{ $brand['header'] }}</div>
    @endif
    <div class="doc-title">{{ __('lba.report.title') }}</div>
    <div class="doc-subtitle">{{ __('lba.report.subtitle') }}</div>
</div>

{{-- Patient + examination identity --}}
<div class="section">
    <div class="section-title">{{ __('lba.report.identity') }}</div>
    <table class="meta">
        <tr>
            <td class="k">{{ __('lba.report.patient') }}</td>
            <td class="v">{{ $patient['name'] }}</td>
            <td class="k">{{ __('lba.report.patient_number') }}</td>
            <td class="v">{{ $patient['number'] }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('lba.report.examination_id') }}</td>
            <td class="v">{{ $examination['id'] }}</td>
            <td class="k">{{ __('lba.report.examination_date') }}</td>
            <td class="v">{{ $examination['captured_at'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('lba.report.mode') }}</td>
            <td class="v">{{ $examination['mode'] }}</td>
            <td class="k">{{ __('lba.report.branch') }}</td>
            <td class="v">
                {{ $branch['name'] ?? '—' }}
                @if (! empty($branch['address']))
                    <div class="brand-line">{{ $branch['address'] }}@if(! empty($branch['city'])){{ ', '.$branch['city'] }}@endif</div>
                @endif
            </td>
        </tr>
    </table>
</div>

{{-- Verified / patient-facing clinical findings --}}
<div class="section">
    <div class="section-title">{{ __('lba.report.clinical_findings') }}</div>
    @if (! empty($summary['findings']))
        <table class="data">
            <thead>
                <tr>
                    <th>{{ __('lba.report.finding') }}</th>
                    <th>{{ __('lba.report.category') }}</th>
                    <th>{{ __('lba.report.notes') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['findings'] as $finding)
                    <tr>
                        <td>{{ $finding['label'] }}</td>
                        <td>{{ $categoryLabels[$finding['category']] ?? $finding['category'] }}</td>
                        <td class="muted">{{ $finding['notes'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="notice">{{ __('lba.report.no_findings') }}</div>
    @endif
</div>

{{-- Observation summary: unvalidated terminology is NEVER presented as a
     severity, disease risk or diagnostic tally. Instead of green/yellow/red
     counts we state, neutrally, that observations remain unvalidated reference
     terminology kept only for internal clinical review. --}}
<div class="section">
    <div class="section-title">{{ __('lba.report.observation_summary') }}</div>
    <div class="notice-neutral">{{ __('lba.report.observation_summary_unvalidated') }}</div>
    <div class="notice-neutral" style="margin-top: 8px;">{{ __('lba.report.observation_summary_note') }}</div>
</div>

{{-- Evidence references --}}
<div class="section">
    <div class="section-title">{{ __('lba.report.evidence') }}</div>
    @if (! empty($evidence))
        <table class="data">
            <thead>
                <tr>
                    <th>{{ __('lba.report.indicator') }}</th>
                    <th>{{ __('lba.report.evidence_type') }}</th>
                    <th>{{ __('lba.report.provenance') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($evidence as $indicatorKey => $links)
                    @foreach ($links as $link)
                        <tr>
                            <td>{{ $indicatorKey }}</td>
                            <td>{{ $evidenceLabels[$link['type']] ?? $link['type'] }}</td>
                            <td>{{ $provenanceLabels[$link['provenance']] ?? $link['provenance'] }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @else
        <div class="notice-neutral">{{ __('lba.report.evidence_none') }}</div>
    @endif
</div>

{{-- Program phase: a program descriptor, kept separate from clinical findings --}}
<div class="section">
    <div class="section-title">{{ __('lba.report.program_phase') }}</div>
    <table class="meta">
        <tr>
            <td class="k">{{ __('lba.assessment.program_phase') }}</td>
            <td class="v">{{ __('lba.assessment.phase_'.$program_phase) }}</td>
        </tr>
    </table>
    <div class="notice-neutral" style="margin-top: 8px;">{{ __('lba.report.program_phase_note') }}</div>
</div>

{{-- Reviewer identity, approval date, report version --}}
<div class="section">
    <div class="section-title">{{ __('lba.report.approval') }}</div>
    <table class="meta">
        <tr>
            <td class="k">{{ __('lba.report.reviewer') }}</td>
            <td class="v">{{ $reviewer['name'] ?? '—' }}</td>
            <td class="k">{{ __('lba.report.approved_at') }}</td>
            <td class="v">{{ $reviewer['decided_at'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('lba.report.report_version') }}</td>
            <td class="v">v{{ $version }}</td>
            <td class="k">{{ __('lba.report.locale') }}</td>
            <td class="v">{{ $locale }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('lba.report.generated_at') }}</td>
            <td class="v">{{ $generated_at }}</td>
            <td class="k">{{ __('lba.report.examination_id') }}</td>
            <td class="v">{{ $examination['id'] }}</td>
        </tr>
    </table>
</div>

{{-- Limitations --}}
<div class="limitations">
    <p><strong>{{ __('lba.report.limitations') }}</strong></p>
    <p>{{ __('lba.report.limitations_text') }}</p>
    <p>{{ __('lba.report.no_therapy') }}</p>
</div>

<div class="footer">
    @if (! empty($brand['footer']))
        <div>{{ $brand['footer'] }}</div>
    @endif
    <div>{{ __('lba.report.footer_notice') }}</div>
</div>

</body>
</html>
