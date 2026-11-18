{{--
    Phase 1B-2B — Clinic topbar tenant context switcher.

    Rendered via PanelsRenderHook::USER_MENU_BEFORE so the verified tenant
    identity sits next to the account menu. The switcher itself is a Livewire
    component so the switch is a CSRF-protected, server-verified round-trip.
--}}
@livewire('tenant-switcher')
