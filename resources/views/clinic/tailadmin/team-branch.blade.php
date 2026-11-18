@extends('principal.layouts.app', ['title' => __('lba.branches.staff.title'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card">
        <div class="border-b border-slate-200 p-4">
            <h2 class="font-semibold text-navy-900">{{ $location->name }}</h2>
            <p class="text-sm text-slate-600">{{ __('lba.team.staff_subtitle') }}</p>
        </div>

        @if ($errors->any())
            <div class="border-b border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="post" action="{{ route('clinic.tailadmin.team.staff.assign', $location) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
            @csrf
            <label class="grid flex-1 gap-1 text-sm">
                {{ __('lba.branches.staff.staff') }}
                <select name="user_id" class="principal-input" required @disabled($candidates === [])>
                    @forelse ($candidates as $id => $name)
                        <option value="{{ $id }}" @selected((string) old('user_id') === (string) $id)>{{ $name }}</option>
                    @empty
                        <option value="">{{ __('lba.branches.staff.no_candidates') }}</option>
                    @endforelse
                </select>
            </label>
            <button class="principal-button principal-button-primary" type="submit" @disabled($candidates === [])>{{ __('lba.branches.staff.assign') }}</button>
        </form>

        <ul class="divide-y divide-slate-100">
            @forelse ($assignments as $assignment)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="wrap-break-word font-semibold">{{ $staffNames[$assignment->user_id] ?? $assignment->user_id }}</p>
                        <p class="text-sm text-slate-500">{{ __('lba.branches.staff.assigned_at') }}: {{ $assignment->assigned_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-3 py-1 text-sm font-medium {{ $assignment->isActive() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ __('lba.branches.staff.status_'.$assignment->status) }}</span>
                        @if ($assignment->isActive())
                            <form method="post" action="{{ route('clinic.tailadmin.team.staff.revoke', $location) }}">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $assignment->user_id }}">
                                <button class="principal-button" type="submit">{{ __('lba.branches.staff.revoke') }}</button>
                            </form>
                        @endif
                    </div>
                </li>
            @empty
                <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.branches.staff.empty') }}</li>
            @endforelse
        </ul>
    </section>

    <a class="principal-button mt-4 inline-flex" href="{{ route('clinic.tailadmin.team') }}">{{ __('principal.brands.cancel') }}</a>
@endsection
