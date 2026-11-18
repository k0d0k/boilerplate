{{--
    "Brand & Mitra" tabs.

    The governance Brand/Partner surface is a single sidebar entry with two
    tabs: "Brand" (brand catalog + report identity + brand–partner relations)
    and "Kelola Mitra" (the partner/tenant registry). Each tab is shown only
    when the acting principal holds the matching resource permission, so a
    principal without one of them sees a single-tab surface — never a dead link.
--}}
@php($active = $active ?? 'brand')
<nav class="mb-5 flex flex-wrap gap-2 border-b border-slate-200" aria-label="{{ __('principal.brands.title') }}">
    @can('viewAny', App\Models\Brand::class)
        <a href="{{ route('principal.tailadmin.brands.index') }}" class="principal-tab" @if ($active === 'brand') aria-current="page" @endif>
            {{ __('principal.brands.tab') }}
        </a>
    @endcan
    @can('viewAny', App\Models\Tenant::class)
        <a href="{{ route('principal.tailadmin.tenants.index') }}" class="principal-tab" @if ($active === 'mitra') aria-current="page" @endif>
            {{ __('principal.tenants.title') }}
        </a>
    @endcan
</nav>
