{{--
    WS9.E — "open tenant" support banner.

    Shown across the Principal panel ONLY while a bounded, read-only support
    context is active. It names the tenant under review, states when the context
    expires and offers the single "Kembali ke Principal" action that closes the
    context. When no context is active it renders nothing.
--}}
<div>
    @if ($active && $tenant)
        <div
            class="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between gap-3 px-4 pt-4 sm:px-6"
            role="status"
            wire:key="lba-support-banner"
        >
            <div class="flex min-w-0 items-center gap-3">
                <span
                    class="inline-flex h-2.5 w-2.5 shrink-0 rounded-full bg-warning-500"
                    aria-hidden="true"
                ></span>

                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('lba.support.banner_title', ['tenant' => $tenant->name]) }}
                    </p>

                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ __('lba.support.banner_hint') }}

                        @if ($expiresAt)
                            · {{ __('lba.support.banner_expires', ['time' => $expiresAt->format('H:i')]) }}
                        @endif
                    </p>
                </div>
            </div>

            <x-filament::button
                color="gray"
                size="sm"
                icon="heroicon-m-arrow-uturn-left"
                wire:click="close"
            >
                {{ __('lba.support.back_to_principal') }}
            </x-filament::button>
        </div>
    @endif
</div>
