@php
    use Filament\Support\Icons\Heroicon;
@endphp
<a href="{{ route('filament.principal.pages.dashboard') }}" class="principal-sidebar__brand mb-8 flex items-center gap-3 px-3 font-semibold text-navy-900">
    <span aria-hidden="true" class="grid size-9 shrink-0 place-items-center rounded-xl bg-navy-900 text-sm text-white">LBA</span>
    <span class="principal-sidebar__brand-name">{{ $systemName ?? config('lba.system_name', 'LBA Cloud') }}</span>
</a>
<nav aria-label="{{ __('principal.shell.navigation') }}" class="principal-sidebar__nav flex flex-col gap-1">
    {{-- ONE navigation definition drives both the desktop rail and the mobile
         drawer, so the active state can never drift between them.

         Menu, groups and order mirror docs/PANELS_NAVIGATION.md ("Principal
         Panel — TailAdmin target") and the panel provider's navigation items:
         Ringkasan Platform, then the governance group (Brand & Mitra,
         Klinik & Cabang, Paket Langganan, Penggunaan & Token AI, Manajer
         Modul, Hak Akses Fitur, Penyedia Pembayaran, Analitik Bisnis, Audit
         Sistem, Pengaturan Global) and finally the Principal-only CRUD
         generator under Pengembangan Modul.

         "Sisakan satu entri Brand & Mitra": brand management and the partner
         registry are the two tabs of ONE Brand & Mitra surface, so no second
         brand menu is shown; "Klinik & Cabang" is the governance catalogue of
         tenant metadata + branch counts, never clinical records. Every link
         targets whichever TailAdmin surface the acting principal may actually
         open, so the shared shell never swaps.

         Each label is repeated in a `title` attribute so it stays readable
         when the desktop sidebar is collapsed to the ~72px icon rail (JS keeps
         the visible label in the DOM for assistive tech by visually hiding it
         instead of removing it).

         The `aria-current` attribute is emitted straight after `href` so the
         active link stays matched by the navigation tests (see
         PrincipalTailAdminTest). --}}
    @php($dashboardLabel = __('lba.dashboard.principal_title'))
    <a class="principal-link" href="{{ route('filament.principal.pages.dashboard') }}" @if (request()->routeIs('filament.principal.pages.dashboard')) aria-current="page" @endif title="{{ $dashboardLabel }}">
        <x-filament::icon :icon="Heroicon::OutlinedSquares2x2" class="principal-link__icon size-5" />
        <span class="principal-link__label">{{ $dashboardLabel }}</span>
    </a>

    <p class="principal-sidebar__group mt-4 px-3 text-xs font-semibold tracking-wide uppercase">{{ __('lba.nav.principal.group_governance') }}</p>

    @php($canBrand = auth()->user()?->can('viewAny', App\Models\Brand::class))
    @if ($canBrand)
        {{-- Brand & Mitra: ONE canonical entry. The partner registry lives as a
             second tab inside the same surface, so it must not reappear as its
             own menu item and it must not also light up the active state of the
             separate Klinik & Cabang entry below. --}}
        @php($brandLabel = __('lba.nav.principal.brands'))
        <a class="principal-link" href="{{ route('principal.tailadmin.brands.index') }}" @if (request()->routeIs('principal.tailadmin.brands.*')) aria-current="page" @endif title="{{ $brandLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedBuildingOffice2" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $brandLabel }}</span>
        </a>
    @endif
    @can('viewAny', App\Models\Tenant::class)
        @php($clinicsLabel = __('lba.nav.principal.clinics'))
        <a class="principal-link" href="{{ route('principal.tailadmin.tenants.index') }}" @if (request()->routeIs('principal.tailadmin.tenants.*')) aria-current="page" @endif title="{{ $clinicsLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedMapPin" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $clinicsLabel }}</span>
        </a>
    @endcan
    @canany([App\Platform\Access\PermissionKey::PLATFORM_PLAN_MANAGE, App\Platform\Access\PermissionKey::PLATFORM_SUBSCRIPTION_VIEW])
        @php($subscriptionsLabel = __('lba.nav.principal.subscriptions'))
        <a class="principal-link" href="{{ route('principal.tailadmin.subscriptions.index') }}" @if (request()->routeIs('principal.tailadmin.subscriptions.*')) aria-current="page" @endif title="{{ $subscriptionsLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedCreditCard" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $subscriptionsLabel }}</span>
        </a>
    @endcanany
    @canany([App\Platform\Access\PermissionKey::PLATFORM_BILLING_MANAGE, App\Platform\Access\PermissionKey::PLATFORM_ANALYTICS_VIEW])
        @php($aiLabel = __('lba.nav.principal.ai_usage'))
        <a class="principal-link" href="{{ route('principal.tailadmin.ai-credits.index') }}" @if (request()->routeIs('principal.tailadmin.ai-credits.*')) aria-current="page" @endif title="{{ $aiLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedCpuChip" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $aiLabel }}</span>
        </a>
    @endcanany
    @can(App\Platform\Access\PermissionKey::PLATFORM_MODULE_MANAGE)
        @php($modulesLabel = __('lba.nav.principal.module_manager'))
        <a class="principal-link" href="{{ route('principal.tailadmin.modules.index') }}" @if (request()->routeIs('principal.tailadmin.modules.*')) aria-current="page" @endif title="{{ $modulesLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedPuzzlePiece" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $modulesLabel }}</span>
        </a>
    @endcan
    @can(App\Platform\Access\PermissionKey::PLATFORM_ENTITLEMENT_MANAGE)
        @php($entitlementsLabel = __('lba.nav.principal.feature_access'))
        <a class="principal-link" href="{{ route('principal.tailadmin.entitlements.index') }}" @if (request()->routeIs('principal.tailadmin.entitlements.*')) aria-current="page" @endif title="{{ $entitlementsLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedKey" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $entitlementsLabel }}</span>
        </a>
    @endcan
    @can(App\Platform\Access\PermissionKey::PLATFORM_PAYMENT_MANAGE)
        @php($paymentsLabel = __('lba.nav.principal.payment_providers'))
        <a class="principal-link" href="{{ route('principal.tailadmin.payment-providers.index') }}" @if (request()->routeIs('principal.tailadmin.payment-providers.*')) aria-current="page" @endif title="{{ $paymentsLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedBanknotes" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $paymentsLabel }}</span>
        </a>
    @endcan
    @can(App\Platform\Access\PermissionKey::PLATFORM_ANALYTICS_VIEW)
        @php($analyticsLabel = __('lba.nav.principal.analytics'))
        <a class="principal-link" href="{{ route('principal.tailadmin.analytics.index') }}" @if (request()->routeIs('principal.tailadmin.analytics.*')) aria-current="page" @endif title="{{ $analyticsLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedChartBar" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $analyticsLabel }}</span>
        </a>
    @endcan
    @can(App\Platform\Access\PermissionKey::PLATFORM_AUDIT_VIEW)
        @php($auditLabel = __('lba.nav.principal.audit'))
        <a class="principal-link" href="{{ route('principal.tailadmin.audit.index') }}" @if (request()->routeIs('principal.tailadmin.audit.*')) aria-current="page" @endif title="{{ $auditLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedShieldCheck" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $auditLabel }}</span>
        </a>
    @endcan
    @can(App\Platform\Access\PermissionKey::PLATFORM_RBAC_MANAGE)
        @php($settingsLabel = __('principal.settings.title'))
        <a class="principal-link" href="{{ route('principal.tailadmin.settings.index') }}" @if (request()->routeIs('principal.tailadmin.settings.*')) aria-current="page" @endif title="{{ $settingsLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedCog6Tooth" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $settingsLabel }}</span>
        </a>
    @endcan

    @can(App\Platform\Crud\BrandCrudDefinition::ABILITY)
        <p class="principal-sidebar__group mt-4 px-3 text-xs font-semibold tracking-wide uppercase">{{ __('principal.crud.group') }}</p>
        @php($crudLabel = __('principal.crud.title'))
        <a class="principal-link" href="{{ route('principal.tailadmin.crud.index') }}" @if (request()->routeIs('principal.tailadmin.crud.*')) aria-current="page" @endif title="{{ $crudLabel }}">
            <x-filament::icon :icon="Heroicon::OutlinedCodeBracket" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $crudLabel }}</span>
        </a>
    @endcan
</nav>
