{{--
    Principal Filament page layout.

    Set through `protected static string $layout` on the Principal dashboard so a
    Filament-rendered page is wrapped by the SAME TailAdmin shell the TailAdmin
    controller pages use — the sidebar, topbar, language switcher and account
    menu never change when a principal moves between the dashboard and the
    governance screens.

    Livewire renders a `->layout()` view as a component and injects the page's
    rendered HTML as `$slot`; `title` is supplied by the page's getLayoutData().

    `filamentAssets` keeps Filament's own styles + scripts so the widgets inside
    the slot (KPI strip, charts, insights) keep their framework assets.
--}}
<x-principal.shell :title="$title ?? ''" panel="principal" :filament-assets="true">
    {{ $slot }}
</x-principal.shell>
