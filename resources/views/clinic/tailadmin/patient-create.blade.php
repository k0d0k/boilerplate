@extends('principal.layouts.app', ['title' => __('lba.nav.clinic.patients'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card max-w-3xl p-5">
        <h2 class="mb-4 font-semibold text-navy-900">{{ __('lba.patients.section_identity') }}</h2>
        <form method="post" action="{{ route('clinic.tailadmin.patients.store') }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="tenant_id" value="{{ $tenantId }}">
            @foreach (['full_name' => 255, 'gender' => 64, 'phone' => 32, 'email' => 255, 'address' => 255, 'city' => 255, 'region' => 255, 'country' => 2] as $field => $length)
                <label class="grid gap-1 text-sm">
                    {{ __('lba.patients.'.$field) }}
                    <input class="principal-input" name="{{ $field }}" maxlength="{{ $length }}" type="{{ $field === 'email' ? 'email' : 'text' }}"
                        value="{{ is_string(old($field)) ? old($field) : '' }}" @required($field === 'full_name')>
                </label>
            @endforeach
            <label class="grid gap-1 text-sm">
                {{ __('lba.patients.date_of_birth') }}
                <input class="principal-input" type="date" name="date_of_birth" max="{{ now()->toDateString() }}" value="{{ is_string(old('date_of_birth')) ? old('date_of_birth') : '' }}">
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.patients.sex') }}
                <select name="sex" class="principal-input">
                    <option value="">—</option>
                    @foreach (Modules\Patients\Models\Patient::SEXES as $sex)
                        <option value="{{ $sex }}" @selected(old('sex') === $sex)>{{ __('lba.patients.sex_'.$sex) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.patients.registration_branch') }}
                <select name="registered_branch_id" class="principal-input" required>
                    <option value="">{{ __('lba.branch_switcher.choose') }}</option>
                    @foreach ($options as $option)
                        <option value="{{ $option->id }}" @selected(old('registered_branch_id', $branchId) === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                {{ __('lba.patients.status') }}
                <select name="status" class="principal-input" required>
                    @foreach (Modules\Patients\Models\Patient::STATUSES as $status)
                        <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ __('lba.patients.status_'.$status) }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex flex-wrap gap-2 sm:col-span-2">
                <button class="principal-button principal-button-primary" @disabled($options === [])>{{ __('principal.brands.save') }}</button>
                <a class="principal-button" href="{{ route('clinic.tailadmin.index', 'patients') }}">{{ __('principal.brands.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection
