@php($panel = $panel ?? 'principal')
{{--
    Controller-rendered Principal/Clinic pages render through the one shared
    TailAdmin shell component. The layout markup, sidebar, topbar, language
    switcher and account menu all live in `components/principal/shell`.

    Blade forwards every variable defined in an extending child view to its
    layout, so the Clinic workspace's `$links` menu is threaded straight into
    the shared shell.
--}}
<x-principal.shell :title="$title ?? ''" :panel="$panel" :links="$links ?? []">
    @yield('content')
</x-principal.shell>
