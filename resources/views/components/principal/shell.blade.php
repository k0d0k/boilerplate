@props(['title' => '', 'panel' => 'principal', 'links' => [], 'filamentAssets' => false])
@php
    use Filament\Support\Icons\Heroicon;
@endphp
@php($systemName = app(App\Platform\Settings\PlatformSettings::class)->systemName())
@php($account = auth()->user())
@php($accountName = (string) ($account?->name ?? ''))
@php($accountInitials = \Illuminate\Support\Str::of($accountName)->trim()->explode(' ')->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '?')
{{--
    The single TailAdmin shell shared by the Principal and Clinic panels.

    Both the panel controllers (via `principal.layouts.app`) and the Principal
    Filament dashboard (via `principal.layouts.filament`) render through this one
    component, so the sidebar, topbar, language switcher and account menu are
    defined exactly once. The menu itself lives in `principal.layouts.sidebar`
    (one definition, one active state).

    `filamentAssets` switches the asset directives: the Filament dashboard still
    renders Filament widgets inside the slot, so that page loads Filament's own
    styles + scripts (which bundle Livewire) instead of the bare Livewire ones.
    Because the Filament dashboard replaces Filament's base layout with this
    shell, the panel's compiled Vite theme (`->viteTheme()`) has to be emitted
    here too — `@filamentStyles` alone only ships the core framework assets, not
    the `lba-*` widget styles or the `fi-size-*` / `fi-grid*` helpers the widgets
    depend on. Plain TailAdmin controller pages keep the Livewire-only
    directives.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" @class(['h-full bg-slate-50', 'fi' => $filamentAssets])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $systemName }}</title>
    @vite(['resources/css/tailadmin/principal.css', 'resources/js/tailadmin/principal.js'])
    @if ($filamentAssets)
        @filamentStyles
        {{-- The panel's compiled Vite theme (the `lba-*` widget styles plus the
             Filament component/utility layers the widgets render against). --}}
        {{ filament()->getTheme()->getHtml() }}
    @else
        @livewireStyles
    @endif
</head>
<body @class(['min-h-full font-sans text-slate-800 antialiased', 'fi-body' => $filamentAssets, 'fi-panel-'.$panel => $filamentAssets])>
    {{-- Shell adapted from TailAdmin Laravel Free (MIT), 62e7261. --}}
    <div class="min-h-screen lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]" data-principal-shell>
        <aside id="principal-sidebar" @class([
            'principal-sidebar',
            'principal-sidebar--navy' => $panel === 'principal',
            'sticky top-0 hidden h-screen flex-col overflow-y-auto px-4 py-6 lg:flex',
            'border-r border-slate-200 bg-white' => $panel !== 'principal',
        ])>
            @include($panel === 'clinic' ? 'clinic.tailadmin.sidebar' : 'principal.layouts.sidebar', ['links' => $links])
        </aside>
        <dialog id="principal-menu" aria-label="{{ __('principal.shell.navigation') }}" data-principal-menu
            @class([
                'principal-sidebar',
                'principal-sidebar--navy' => $panel === 'principal',
                'fixed inset-y-0 left-0 m-0 h-dvh max-h-none w-[min(18rem,88vw)] max-w-none overflow-y-auto p-5 shadow-xl backdrop:bg-slate-950/50 lg:hidden',
                'bg-white' => $panel !== 'principal',
            ])>
            <form method="dialog" class="mb-4 flex justify-end">
                <button class="principal-button">{{ __('principal.shell.close') }}</button>
            </form>
            @include($panel === 'clinic' ? 'clinic.tailadmin.sidebar' : 'principal.layouts.sidebar', ['links' => $links])
        </dialog>
        <div class="min-w-0">
            <header class="sticky top-0 z-20 flex min-h-16 flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-white px-4 py-2 sm:px-6">
                <div class="flex items-center gap-3">
                    <button type="button" aria-label="{{ __('principal.shell.navigation') }}" aria-haspopup="dialog" aria-controls="principal-menu"
                        data-principal-menu-trigger class="principal-button lg:hidden">
                        <x-filament::icon :icon="Heroicon::OutlinedBars3" class="size-5" />
                    </button>
                    {{-- Desktop rail toggle: collapses the sidebar to the ~72px
                         icon rail documented in docs/DESIGN_SYSTEM.md. The state
                         is mirrored on the shell root as `data-principal-collapsed`
                         and persisted so it survives navigation. --}}
                    <button type="button" aria-controls="principal-sidebar" aria-expanded="true"
                        aria-label="{{ __('principal.shell.collapse_sidebar') }}"
                        data-principal-sidebar-toggle
                        data-label-expand="{{ __('principal.shell.expand_sidebar') }}"
                        data-label-collapse="{{ __('principal.shell.collapse_sidebar') }}"
                        title="{{ __('principal.shell.collapse_sidebar') }}"
                        class="principal-button hidden lg:inline-flex">
                        <x-filament::icon :icon="Heroicon::OutlinedBars3" class="size-5" />
                    </button>
                    <span class="text-sm font-semibold text-navy-900">{{ __('lba.panel.'.$panel) }}</span>
                </div>
                <div class="flex items-center gap-2">
                    @livewire('language-switcher', ['tailAdmin' => true])
                    {{-- Account self-service lives in the top-right menu, not the
                         sidebar: "Profil Akun", "Ubah Kata Sandi" and "Keluar".
                         The button shows the acting identity's name and photo,
                         falling back to their initials. --}}
                    <div class="relative" data-principal-account>
                        <button type="button" class="principal-account-button" aria-haspopup="menu" aria-expanded="false"
                            aria-label="{{ __('lba.account.nav') }}" data-principal-account-trigger>
                            @if ($account?->hasPhoto())
                                <img src="{{ route('account.photo') }}" alt="" class="size-8 shrink-0 rounded-full object-cover">
                            @else
                                <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-700 text-xs font-semibold text-white">{{ $accountInitials }}</span>
                            @endif
                            <span class="max-w-[10rem] truncate" data-principal-account-name>{{ $accountName }}</span>
                            <span aria-hidden="true" class="text-xs">▾</span>
                        </button>
                        <div role="menu" hidden data-principal-account-menu class="principal-account-menu">
                            <a role="menuitem" href="{{ route('filament.'.$panel.'.pages.account-profile') }}" class="principal-account-item">{{ __('lba.account.nav') }}</a>
                            <a role="menuitem" href="{{ route('filament.'.$panel.'.pages.change-password') }}" class="principal-account-item">{{ __('lba.password_change.nav') }}</a>
                            <form method="post" action="{{ route('filament.'.$panel.'.auth.logout') }}">
                                @csrf
                                <button type="submit" role="menuitem" class="principal-account-item">{{ __('principal.shell.logout') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>
            {{-- WS9.E — the bounded, read-only "open tenant" support banner.
                 Filament-backed pages (the dashboard) already render it through
                 the panel's PAGE_START render hook, while the TailAdmin
                 controller pages never hit that hook, so the shell only supplies
                 it for those non-Filament surfaces. Without this split the banner
                 would render twice on the Filament dashboard. --}}
            @if ($panel === 'principal' && ! $filamentAssets)
                @include('filament.principal.support-banner')
            @endif
            <main class="mx-auto max-w-7xl px-4 py-7 sm:px-6 lg:px-8">
                {{-- Filament-backed pages render their own single <h1> inside
                     the slot (the dashboard hero band), so the shell must not
                     add a second page title there. Plain TailAdmin controller
                     pages have no hero and keep the shell title. --}}
                @unless ($filamentAssets)
                    <div class="mb-6">
                        <h1 class="text-2xl font-semibold tracking-tight text-navy-900 sm:text-3xl">{{ $title }}</h1>
                    </div>
                @endunless
                @if (session('success'))
                    <p role="status" class="mb-5 rounded-lg border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-700">{{ session('success') }}</p>
                @endif
                @if ($errors->any())
                    <ul role="alert" class="mb-5 list-inside list-disc rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
    @if ($filamentAssets)
        @filamentScripts(withCore: true)
    @else
        @livewireScripts
    @endif
</body>
</html>
