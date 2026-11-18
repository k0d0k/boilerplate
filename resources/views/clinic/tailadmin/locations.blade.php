@extends('principal.layouts.app', ['title' => __('lba.locations.title'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-4">
            <div>
                <h2 class="font-semibold text-navy-900">{{ __('lba.locations.title') }}</h2>
                <p class="text-sm text-slate-600">{{ __('lba.locations.subtitle') }}</p>
            </div>
            @if ($canManage)
                <a class="principal-button principal-button-primary" href="{{ route('clinic.tailadmin.locations.create') }}">{{ __('lba.locations.add') }}</a>
            @endif
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse ($locations as $location)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="wrap-break-word font-semibold">{{ $location->name }}</p>
                        <p class="text-sm text-slate-600">
                            {{ __('lba.locations.type_'.$location->location_type) }}
                            @if ($location->city) · {{ $location->city }} @endif
                            · {{ __('lba.branches.status_'.$location->status) }}
                        </p>
                        <p class="text-sm text-slate-500">
                            {{ $location->address ?: '—' }}
                        </p>
                        <p class="text-sm text-slate-500">
                            {{ __('lba.locations.window') }}: {{ $location->opens_at ?: \App\Platform\Branches\Models\TenantBranch::DEFAULT_OPENS_AT }}–{{ $location->closes_at ?: \App\Platform\Branches\Models\TenantBranch::DEFAULT_CLOSES_AT }}
                            @if (! $location->isPermanent())
                                · {{ $location->operates_from?->translatedFormat('d M Y H:i') ?? '—' }} → {{ $location->operates_until?->translatedFormat('d M Y H:i') ?? '—' }}
                            @endif
                        </p>
                    </div>
                    @if ($canManage)
                        <div class="flex flex-wrap items-center gap-2">
                            <a class="principal-button" href="{{ route('clinic.tailadmin.locations.edit', $location) }}">{{ __('lba.branches.edit') }}</a>
                            @if ($location->isActive())
                                <form method="post" action="{{ route('clinic.tailadmin.locations.deactivate', $location) }}">
                                    @csrf
                                    <button class="principal-button" type="submit">{{ __('lba.branches.deactivate') }}</button>
                                </form>
                            @else
                                <form method="post" action="{{ route('clinic.tailadmin.locations.activate', $location) }}">
                                    @csrf
                                    <button class="principal-button principal-button-primary" type="submit">{{ __('lba.branches.activate') }}</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.locations.empty') }}</li>
            @endforelse
        </ul>
    </section>
@endsection
