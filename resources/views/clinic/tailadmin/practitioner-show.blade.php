@extends('principal.layouts.app', ['title' => $practitioner->display_name, 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        <section class="principal-card">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 p-4">
                <div class="min-w-0">
                    <h2 class="wrap-break-word font-semibold text-navy-900">
                        {{ $practitioner->display_name }}
                        @if ($practitioner->title) · {{ $practitioner->title }} @endif
                    </h2>
                    <p class="text-sm text-slate-600">{{ __('lba.practitioners.professional_body') }}: {{ $practitioner->professional_body ?: '—' }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-sm font-medium
                        @class([
                            'bg-emerald-100 text-emerald-700' => $practitioner->isVerified(),
                            'bg-amber-100 text-amber-700' => $practitioner->isPending(),
                            'bg-rose-100 text-rose-700' => $practitioner->isSuspended(),
                            'bg-slate-100 text-slate-600' => $practitioner->isInactive(),
                        ])">{{ __('lba.practitioners.status_'.$practitioner->status) }}</span>
                    @if ($canManage)
                        <a class="principal-button" href="{{ route('clinic.tailadmin.practitioners.edit', $practitioner) }}">{{ __('lba.practitioners.edit') }}</a>
                    @endif
                    <a class="principal-button" href="{{ route('clinic.tailadmin.index', 'practitioners') }}">{{ __('lba.practitioners.back') }}</a>
                </div>
            </div>

            @if ($errors->any())
                <div class="border-b border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{{ $errors->first() }}</div>
            @endif

            <dl class="grid gap-x-6 gap-y-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.practitioners.specialities') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $practitioner->specialities ? implode(', ', $practitioner->specialities) : '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.practitioners.verified_at') }}</dt>
                    <dd class="font-medium">{{ $practitioner->verified_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.practitioners.expires_at') }}</dt>
                    <dd class="font-medium">{{ $practitioner->expires_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                </div>
                <div class="min-w-0 sm:col-span-2 lg:col-span-3">
                    <dt class="text-sm text-slate-500">{{ __('lba.practitioners.notes') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $practitioner->notes ?: '—' }}</dd>
                </div>
            </dl>

            @if ($canVerify || $canManage)
                <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 p-4">
                    @if ($canVerify && $practitioner->isPending())
                        <form method="post" action="{{ route('clinic.tailadmin.practitioners.verify', $practitioner) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.practitioners.expires_at') }}
                                <input class="principal-input" type="date" name="expires_at" value="{{ old('expires_at') }}">
                            </label>
                            <button class="principal-button principal-button-primary" type="submit">{{ __('lba.practitioners.verify') }}</button>
                        </form>
                    @endif
                    @if ($canVerify && ! $practitioner->isSuspended() && ! $practitioner->isInactive())
                        <form method="post" action="{{ route('clinic.tailadmin.practitioners.suspend', $practitioner) }}">
                            @csrf
                            <button class="principal-button" type="submit">{{ __('lba.practitioners.suspend') }}</button>
                        </form>
                    @endif
                    @if ($canVerify && ($practitioner->isSuspended() || $practitioner->isInactive()))
                        <form method="post" action="{{ route('clinic.tailadmin.practitioners.reactivate', $practitioner) }}">
                            @csrf
                            <button class="principal-button" type="submit">{{ __('lba.practitioners.reactivate') }}</button>
                        </form>
                    @endif
                    @if ($canManage && ! $practitioner->isInactive())
                        <form method="post" action="{{ route('clinic.tailadmin.practitioners.archive', $practitioner) }}">
                            @csrf
                            <button class="principal-button" type="submit">{{ __('lba.practitioners.archive') }}</button>
                        </form>
                    @endif
                </div>
            @endif
        </section>

        <section class="principal-card">
            <div class="border-b border-slate-200 p-4">
                <h3 class="font-semibold text-navy-900">{{ __('lba.practitioners.branches.title') }}</h3>
            </div>

            @if ($canManage)
                <form method="post" action="{{ route('clinic.tailadmin.practitioners.branches.authorize', $practitioner) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
                    @csrf
                    <label class="grid flex-1 gap-1 text-sm">
                        {{ __('lba.practitioners.branches.branch') }}
                        <select name="branch_id" class="principal-input" required @disabled($branchOptions === [])>
                            <option value="">{{ __('lba.branch_switcher.choose') }}</option>
                            @foreach ($branchOptions as $id => $name)
                                <option value="{{ $id }}" @selected((string) old('branch_id') === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="principal-button principal-button-primary" type="submit" @disabled($branchOptions === [])>{{ __('lba.practitioners.branches.authorize') }}</button>
                </form>
            @endif

            <ul class="divide-y divide-slate-100">
                @forelse ($authorizations as $authorization)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-semibold">{{ $authorization->branch?->name ?? $authorization->branch_id }}</p>
                            <p class="text-sm text-slate-500">{{ $authorization->authorized_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-sm font-medium {{ $authorization->isActive() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ __('lba.practitioners.branches.status_'.$authorization->status) }}
                            </span>
                            @if ($canManage && $authorization->isActive())
                                <form method="post" action="{{ route('clinic.tailadmin.practitioners.branches.revoke', $practitioner) }}">
                                    @csrf
                                    <input type="hidden" name="branch_id" value="{{ $authorization->branch_id }}">
                                    <button class="principal-button" type="submit">{{ __('lba.practitioners.branches.revoke') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.practitioners.branches.empty') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card">
            <div class="border-b border-slate-200 p-4">
                <h3 class="font-semibold text-navy-900">{{ __('lba.practitioners.credentials.title') }}</h3>
            </div>

            @if ($canManage)
                <form method="post" action="{{ route('clinic.tailadmin.practitioners.credentials.store', $practitioner) }}" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.practitioners.credentials.type') }}
                        <select name="credential_type" class="principal-input" required>
                            @foreach (Modules\Practitioners\Models\PractitionerCredential::TYPES as $type)
                                <option value="{{ $type }}" @selected(old('credential_type') === $type)>{{ __('lba.practitioners.credentials.type_'.$type) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.practitioners.credentials.jurisdiction') }}
                        <input class="principal-input" name="jurisdiction" maxlength="128" value="{{ is_string(old('jurisdiction')) ? old('jurisdiction') : '' }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.practitioners.credentials.reference') }}
                        <input class="principal-input" name="reference_number" maxlength="128" value="{{ is_string(old('reference_number')) ? old('reference_number') : '' }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.practitioners.credentials.issued_at') }}
                        <input class="principal-input" type="date" name="issued_at" value="{{ is_string(old('issued_at')) ? old('issued_at') : '' }}">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.practitioners.credentials.expires_at') }}
                        <input class="principal-input" type="date" name="expires_at" value="{{ is_string(old('expires_at')) ? old('expires_at') : '' }}">
                    </label>
                    <div class="flex items-end">
                        <button class="principal-button principal-button-primary" type="submit">{{ __('lba.practitioners.credentials.add') }}</button>
                    </div>
                </form>
            @endif

            <ul class="divide-y divide-slate-100">
                @forelse ($credentials as $credential)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ __('lba.practitioners.credentials.type_'.$credential->credential_type) }}</p>
                            <p class="text-sm text-slate-500">
                                {{ $credential->maskedReference() }}
                                @if ($credential->jurisdiction) · {{ $credential->jurisdiction }} @endif
                                @if ($credential->expires_at) · {{ __('lba.practitioners.credentials.expires_at') }}: {{ $credential->expires_at->translatedFormat('d M Y') }} @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-sm font-medium {{ $credential->isVerified() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ __('lba.practitioners.credentials.status_'.$credential->status) }}
                            </span>
                            @if ($canVerify && $credential->isPending())
                                <form method="post" action="{{ route('clinic.tailadmin.practitioners.credentials.verify', [$practitioner, $credential]) }}">
                                    @csrf
                                    <button class="principal-button" type="submit">{{ __('lba.practitioners.credentials.verify') }}</button>
                                </form>
                                <form method="post" action="{{ route('clinic.tailadmin.practitioners.credentials.reject', [$practitioner, $credential]) }}">
                                    @csrf
                                    <button class="principal-button" type="submit">{{ __('lba.practitioners.credentials.reject') }}</button>
                                </form>
                            @endif
                            @if ($canVerify && $credential->isVerified())
                                <form method="post" action="{{ route('clinic.tailadmin.practitioners.credentials.revoke', [$practitioner, $credential]) }}">
                                    @csrf
                                    <button class="principal-button" type="submit">{{ __('lba.practitioners.credentials.revoke') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.practitioners.credentials.empty') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
