{{--
    Phase 2B — Clinic topbar branch context switcher.

    Rendered via PanelsRenderHook::USER_MENU_BEFORE, after the tenant switcher, so
    the verified branch identity sits next to the tenant identity. The switcher is
    a Livewire component so the switch is a CSRF-protected, server-verified
    round-trip.
--}}
@livewire('branch-switcher')
