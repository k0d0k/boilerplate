@extends('principal.layouts.app', ['title' => $patient->full_name, 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        <section class="principal-card">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 p-4">
                <div class="min-w-0">
                    <h2 class="wrap-break-word font-semibold text-navy-900">{{ $patient->full_name }}</h2>
                    <p class="text-sm text-slate-600">{{ $patient->patient_number }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-sm font-medium {{ $patient->isActive() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                        {{ __('lba.patients.status_'.$patient->status) }}
                    </span>
                    @if ($canManage)
                        <a class="principal-button" href="{{ route('clinic.tailadmin.patients.edit', $patient) }}">{{ __('lba.patients.edit') }}</a>
                        @if ($patient->isActive())
                            <form method="post" action="{{ route('clinic.tailadmin.patients.archive', $patient) }}">
                                @csrf
                                <button class="principal-button" type="submit">{{ __('lba.patients.archive') }}</button>
                            </form>
                        @else
                            <form method="post" action="{{ route('clinic.tailadmin.patients.restore', $patient) }}">
                                @csrf
                                <button class="principal-button" type="submit">{{ __('lba.patients.restore') }}</button>
                            </form>
                        @endif
                    @endif
                    <a class="principal-button" href="{{ route('clinic.tailadmin.index', 'patients') }}">{{ __('lba.patients.back') }}</a>
                </div>
            </div>

            <dl class="grid gap-x-6 gap-y-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    'date_of_birth' => $patient->date_of_birth?->toDateString(),
                    'sex' => $patient->sex !== null ? __('lba.patients.sex_'.$patient->sex) : null,
                    'gender' => $patient->gender,
                    'phone' => $patient->phone,
                    'email' => $patient->email,
                    'address' => $patient->address,
                    'city' => $patient->city,
                    'region' => $patient->region,
                    'country' => $patient->country,
                    'registration_branch' => $patient->registeredBranch?->name,
                    'registration_date' => $patient->registered_at?->translatedFormat('d M Y H:i'),
                ] as $field => $value)
                    <div class="min-w-0">
                        <dt class="text-sm text-slate-500">{{ __('lba.patients.'.$field) }}</dt>
                        <dd class="wrap-break-word font-medium">{{ $value !== null && $value !== '' ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="principal-card">
            <div class="border-b border-slate-200 p-4">
                <h3 class="font-semibold text-navy-900">{{ __('lba.patients.appointments.title') }}</h3>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($appointments as $appointment)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $appointment->starts_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                            <p class="text-sm text-slate-600">
                                {{ __('lba.appointments.status_'.$appointment->status) }}
                                @if ($appointment->branch) · {{ $appointment->branch->name }} @endif
                            </p>
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.patients.appointments.empty') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
