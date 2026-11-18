@extends('principal.layouts.app', ['title' => $label, 'panel' => 'clinic'])

@section('content')
    <section class="principal-card">
        @if ($registry === 'examinations')
            @php($modes = ['' => __('lba.exams.nav_all')] + collect(\Modules\LBA\Models\Examination::MODES)->mapWithKeys(fn (string $m): array => [$m => __('lba.exams.nav_'.$m)])->all())
            <nav aria-label="{{ __('lba.exams.nav_group') }}" class="flex flex-wrap gap-2 border-b border-slate-200 p-4">
                @foreach ($modes as $value => $tab)
                    <a class="principal-button {{ $mode === $value ? 'principal-button-primary' : '' }}"
                        href="{{ route('clinic.tailadmin.index', array_filter(['registry' => $registry, 'mode' => $value, 'search' => $search])) }}"
                        @if ($mode === $value) aria-current="page" @endif>{{ $tab }}</a>
                @endforeach
                @if (\Modules\LBA\Filament\Pages\ReviewerInbox::canAccess())
                    <a class="principal-button" href="{{ route('clinic.tailadmin.reviews.index') }}">{{ __('lba.nav.clinic.review_results') }}</a>
                @endif
            </nav>
        @endif
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-4">
            <form method="get" action="{{ route('clinic.tailadmin.index', $registry) }}" class="flex min-w-0 flex-wrap items-end gap-3">
                @if ($mode !== '')
                    <input type="hidden" name="mode" value="{{ $mode }}">
                @endif
                <label class="grid gap-1 text-sm">
                    {{ __('principal.clinic.search') }}
                    <input type="search" name="search" value="{{ $search }}" maxlength="255" class="principal-input">
                </label>
                <button class="principal-button">{{ __('principal.brands.apply') }}</button>
            </form>
            @if ($createUrl)
                <a class="principal-button principal-button-primary" href="{{ $createUrl }}">{{ __('principal.clinic.add') }}</a>
            @endif
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse ($records as $record)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        @if ($registry === 'patients')
                            <p class="wrap-break-word font-semibold">{{ $record->full_name }}</p>
                            <p class="text-sm text-slate-600">{{ $record->patient_number }}</p>
                        @elseif ($registry === 'practitioners')
                            <p class="wrap-break-word font-semibold">{{ $record->display_name }}</p>
                            <p class="text-sm text-slate-600">{{ __('lba.practitioners.status_'.$record->status) }}</p>
                        @else
                            <p class="wrap-break-word font-semibold">{{ $record->patient?->full_name ?? '—' }}</p>
                            <p class="text-sm text-slate-600">
                                @if ($registry === 'appointments')
                                    {{ $record->starts_at?->translatedFormat('d M Y H:i') }} · {{ __('lba.appointments.status_'.$record->status) }}
                                @elseif ($registry === 'consultations')
                                    {{ $record->scheduled_at?->translatedFormat('d M Y H:i') }} · {{ __('lba.consultations.status_'.$record->status) }}
                                @else
                                    {{ $record->captured_at?->translatedFormat('d M Y H:i') }} · {{ __('lba.exams.mode_'.$record->mode) }} · {{ __('lba.exams.status_'.$record->status) }}
                                @endif
                                @if ($record->branch) · {{ $record->branch->name }} @endif
                            </p>
                        @endif
                    </div>
                    @php($openUrl = match ($registry) {
                        'patients' => route('clinic.tailadmin.patients.show', $record),
                        'practitioners' => route('clinic.tailadmin.practitioners.show', $record),
                        'examinations' => route('clinic.tailadmin.examinations.show', $record),
                        'consultations' => route('clinic.tailadmin.consultations.show', $record),
                        default => $resource::getUrl('view', ['record' => $record], panel: 'clinic'),
                    })
                    <a class="principal-button" href="{{ $openUrl }}">{{ __('principal.clinic.open') }}</a>
                </li>
            @empty
                <li class="p-8 text-center text-sm text-slate-500">{{ __('principal.clinic.empty') }}</li>
            @endforelse
        </ul>
        @if ($records->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $records->links() }}</div>
        @endif
    </section>
@endsection
