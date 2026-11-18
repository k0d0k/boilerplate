<a href="{{ route('clinic.tailadmin.dashboard') }}" class="mb-8 flex items-center gap-3 px-3 font-semibold text-navy-900">
    <span aria-hidden="true" class="grid size-9 place-items-center rounded-xl bg-navy-900 text-sm text-white">LBA</span>
    LBA Cloud
</a>
<nav aria-label="{{ __('principal.shell.navigation') }}" class="flex flex-col gap-2">
    <a class="principal-link" href="{{ route('clinic.tailadmin.dashboard') }}" @if (request()->routeIs('clinic.tailadmin.dashboard')) aria-current="page" @endif>{{ __('principal.clinic.workspace') }}</a>
    @foreach ($links as $key => $name)
        <a class="principal-link" href="{{ \App\Http\Controllers\Clinic\WorkspaceController::navUrl($key) }}" @if (request()->route('registry') === $key || ($key === 'reports' && request()->routeIs('clinic.tailadmin.reports')) || ($key === 'reviews' && request()->routeIs('clinic.tailadmin.reviews.*')) || ($key === 'pos' && request()->routeIs('clinic.tailadmin.pos*')) || ($key === 'transactions' && request()->routeIs('clinic.tailadmin.transactions')) || ($key === 'wallet' && request()->routeIs('clinic.tailadmin.wallet')) || ($key === 'products' && request()->routeIs('clinic.tailadmin.products*')) || ($key === 'inventory' && request()->routeIs('clinic.tailadmin.inventory*')) || ($key === 'orders' && request()->routeIs('clinic.tailadmin.orders*')) || request()->routeIs('clinic.tailadmin.'.$key) && in_array($key, ['locations', 'events', 'sina', 'team', 'settings'], true)) aria-current="page" @endif>{{ $name }}</a>
    @endforeach
    <a class="principal-link" href="{{ route('filament.clinic.pages.dashboard') }}">{{ __('principal.clinic.legacy') }}</a>
</nav>
