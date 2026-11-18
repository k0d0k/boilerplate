@extends('principal.layouts.app', ['title' => __('principal.clinic.workspace'), 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4 md:grid-cols-2">
        <section class="principal-card grid content-start gap-3 p-5">
            <h2 class="font-semibold text-navy-900">{{ __('lba.switcher.current_aria') }}</h2>
            <p>{{ $tenant->name }}</p>
            @if (count($tenantOptions) > 1)
                <form method="post" action="{{ route('clinic.tailadmin.switch-tenant') }}" class="flex flex-wrap gap-2">
                    @csrf
                    <select name="tenant_id" class="principal-input flex-1" aria-label="{{ __('lba.switcher.choose') }}" required>
                        @foreach ($tenantOptions as $option)
                            <option value="{{ $option->id }}" @selected($option->id === $tenant->getKey())>{{ $option->name }}{{ $option->brandLabel ? ' · '.$option->brandLabel : '' }}</option>
                        @endforeach
                    </select>
                    <button class="principal-button">{{ __('principal.clinic.apply_selection') }}</button>
                </form>
            @endif
        </section>
        <section class="principal-card grid content-start gap-3 p-5">
            <h2 class="font-semibold text-navy-900">{{ __('lba.branch_switcher.current_aria') }}</h2>
            <p>{{ $branch?->name ?? '—' }}</p>
            @if (count($branchOptions) > 1)
                <form method="post" action="{{ route('clinic.tailadmin.switch-branch') }}" class="flex flex-wrap gap-2">
                    @csrf
                    <input type="hidden" name="tenant_id" value="{{ $tenant->id }}">
                    <select name="branch_id" class="principal-input flex-1" aria-label="{{ __('lba.branch_switcher.choose') }}" required>
                        <option value="">{{ __('lba.branch_switcher.choose') }}</option>
                        @foreach ($branchOptions as $option)
                            <option value="{{ $option->id }}" @selected($option->id === $branch?->getKey())>{{ $option->name }}</option>
                        @endforeach
                    </select>
                    <button class="principal-button">{{ __('principal.clinic.apply_selection') }}</button>
                </form>
            @elseif ($branchOptions === [] && $branchSetupUrl)
                <a class="principal-button justify-self-start" href="{{ $branchSetupUrl }}">{{ __('lba.branch_switcher.setup') }}</a>
            @endif
        </section>
        @foreach ($links as $key => $label)
            <a class="principal-card p-5 font-semibold text-brand-700 hover:bg-brand-50" href="{{ \App\Http\Controllers\Clinic\WorkspaceController::navUrl($key) }}">{{ $label }}</a>
        @endforeach
    </div>
@endsection
