<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('lba.account.email_verify_result_title') }} — LBA Cloud</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-slate-50 text-lba-navy antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-6 py-12">
        <div class="mb-8 flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-lba-navy text-white" aria-hidden="true">
                <svg viewBox="0 0 32 32" fill="none" class="h-5 w-5">
                    <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                </svg>
            </span>
            <span class="text-sm font-semibold tracking-[0.2em] text-lba-navy">LBA CLOUD</span>
        </div>

        <div class="rounded-2xl border border-lba-line bg-white p-8 shadow-sm">
            @if ($verified)
                <h1 class="text-xl font-semibold text-lba-navy">{{ __('lba.account.email_verified_title') }}</h1>
                <p class="mt-3 text-sm text-lba-ink">{{ __('lba.account.email_verified_body') }}</p>
            @else
                <h1 class="text-xl font-semibold text-lba-navy">{{ __('lba.account.email_verify_failed_title') }}</h1>
                <p class="mt-3 text-sm text-lba-ink">{{ __('lba.account.email_verify_failed_body') }}</p>
            @endif

            <a href="{{ route('login') }}" class="lba-btn lba-btn--primary mt-6 inline-flex w-full justify-center">
                {{ __('lba.password_reset.back_to_login') }}
            </a>
        </div>

        <p class="mt-6 text-center text-xs text-lba-ink">{{ __('lba.password_reset.footnote') }}</p>
    </main>
</body>
</html>
