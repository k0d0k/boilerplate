{{--
    Phase 8A — "Hasil Pemeriksaan Saya".

    Lists only the acting identity's own APPROVED report versions. Preview and
    download link to the self-scoped private stream endpoints.
--}}
<x-filament-panels::page>
    @php($reports = $this->reports())

    @if ($reports->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.results.empty') }}
        </p>
    @else
        <ul role="list" class="space-y-3">
            @foreach ($reports as $report)
                <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="font-semibold text-gray-950 dark:text-white">
                        {{ __('lba.patient.results.version', ['version' => $report->version]) }}
                    </p>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->branchName($report) }}
                        @if ($report->approved_at)
                            <span aria-hidden="true">·</span>
                            {{ $report->approved_at->translatedFormat('d M Y') }}
                        @endif
                    </p>

                    <div class="mt-3 flex gap-3">
                        <a
                            href="{{ $this->previewUrl($report) }}"
                            target="_blank"
                            rel="noopener"
                            class="text-sm font-medium"
                        >
                            {{ __('lba.patient.results.preview') }}
                        </a>

                        <a href="{{ $this->downloadUrl($report) }}" class="text-sm font-medium">
                            {{ __('lba.patient.results.download') }}
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
