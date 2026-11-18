{{--
    WS9.E — Principal render hook for the "open tenant" support banner.

    Rendered via PanelsRenderHook::PAGE_START so the active support context (and
    its "Kembali ke Principal" action) is visible on every Principal page while
    it is open. The banner component itself renders nothing when inactive.
--}}
@livewire('support-tenant-banner')
