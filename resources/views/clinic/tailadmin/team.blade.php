@extends('principal.layouts.app', ['title' => __('lba.team.title'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-4">
            <div>
                <h2 class="font-semibold text-navy-900">{{ __('lba.team.title') }}</h2>
                <p class="text-sm text-slate-600">{{ __('lba.team.subtitle') }}</p>
            </div>
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse ($locations as $location)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="wrap-break-word font-semibold">{{ $location->name }}</p>
                        <p class="text-sm text-slate-600">
                            {{ __('lba.locations.type_'.$location->location_type) }}
                            · {{ __('lba.branches.status_'.$location->status) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700">{{ __('lba.team.staff_count', ['count' => $location->active_staff_count]) }}</span>
                        @if ($canManage)
                            <a class="principal-button" href="{{ route('clinic.tailadmin.team.show', $location) }}">{{ __('lba.team.manage_staff') }}</a>
                        @endif
                    </div>
                </li>
            @empty
                <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.team.empty') }}</li>
            @endforelse
        </ul>
    </section>
@endsection
