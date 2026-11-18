@extends('principal.layouts.app', ['title' => __('lba.reports.title'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card">
        <p class="border-b border-slate-200 p-4 text-sm text-slate-600">{{ __('lba.reports.notice') }}</p>
        <ul class="divide-y divide-slate-100">
            @forelse ($reports as $report)
                @php($examination = $report->assessment?->examination)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="wrap-break-word font-semibold">{{ $examination?->patient?->full_name ?? '—' }}</p>
                        <p class="text-sm text-slate-600">{{ $examination?->branch?->name ?? '—' }} · {{ $report->generated_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a class="principal-button" href="{{ route('lba.reports.preview', $report) }}">{{ __('principal.crud.preview') }}</a>
                        <a class="principal-button" href="{{ route('lba.reports.download', $report) }}">{{ __('principal.clinic.download') }}</a>
                    </div>
                </li>
            @empty
                <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.reports.empty') }}</li>
            @endforelse
        </ul>
        @if ($reports->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $reports->links() }}</div>
        @endif
    </section>
@endsection
