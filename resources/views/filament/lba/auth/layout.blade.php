@props([
    'livewire' => null,
    'hasTopbar' => true,
    'maxContentWidth' => null,
    'maxWidth' => null,
])

<x-filament-panels::layout.base :livewire="$livewire" class="lba-auth-body">
    <div class="lba-auth">
        <aside class="lba-auth__aside">
            <svg class="lba-auth__motif" viewBox="0 0 400 400" fill="none" aria-hidden="true">
                <circle cx="200" cy="200" r="150" stroke="currentColor" stroke-width="1" stroke-opacity="0.18" />
                <circle cx="200" cy="200" r="104" stroke="currentColor" stroke-width="1" stroke-opacity="0.28" />
                <circle cx="200" cy="200" r="58" stroke="currentColor" stroke-width="1" stroke-opacity="0.4" />
                <g stroke="currentColor" stroke-width="1" stroke-opacity="0.32">
                    <path d="M200 42V104" />
                    <path d="M200 296V358" />
                    <path d="M42 200H104" />
                    <path d="M296 200H358" />
                    <path d="M88 88 132 132" />
                    <path d="M268 268 312 312" />
                </g>
                <g fill="currentColor" fill-opacity="0.75">
                    <circle cx="200" cy="104" r="4.5" />
                    <circle cx="200" cy="296" r="4.5" />
                    <circle cx="104" cy="200" r="4.5" />
                    <circle cx="296" cy="200" r="4.5" />
                    <circle cx="132" cy="132" r="3.5" />
                    <circle cx="268" cy="268" r="3.5" />
                </g>
            </svg>

            <div class="lba-auth__brand">
                <span class="lba-auth__brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                    </svg>
                </span>
                <span class="lba-auth__brand-name">LBA CLOUD</span>
            </div>

            <div class="lba-auth__pitch">
                <h2 class="lba-auth__headline">
                    {{ __('lba.auth.headline_first') }}<br /><span>{{ __('lba.auth.headline_second') }}</span>
                </h2>
                <p class="lba-auth__subtitle">
                    {{ __('lba.auth.subtitle') }}
                </p>
            </div>

            <p class="lba-auth__footnote">{{ __('lba.auth.footnote') }}</p>
        </aside>

        <main class="lba-auth__main">
            <div class="lba-auth__card">
                <div class="lba-auth__mobile-brand">
                    <span class="lba-auth__brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none">
                            <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                        </svg>
                    </span>
                    <span class="lba-auth__brand-name">LBA CLOUD</span>
                </div>

                {{ $slot }}
            </div>

            <p class="lba-auth__legal">
                {{ __('lba.auth.legal', ['year' => date('Y')]) }}
            </p>
        </main>
    </div>
</x-filament-panels::layout.base>
