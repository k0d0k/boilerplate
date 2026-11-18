<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('lba.staff_invitation.title') }} — LBA Cloud</title>
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
            <h1 class="text-xl font-semibold text-lba-navy">{{ __('lba.staff_invitation.title') }}</h1>

            @if ($membership === null)
                <p class="mt-4 text-sm text-lba-ink">{{ __('lba.staff_invitation.invalid') }}</p>
                <a href="{{ route('login') }}" class="mt-6 inline-flex lba-btn lba-btn--primary w-full justify-center">
                    {{ __('lba.staff_invitation.back_to_login') }}
                </a>
            @elseif ($requiresPasswordSetup)
                <p class="mt-4 text-sm text-lba-ink">
                    {{ __('lba.staff_invitation.intro', ['name' => $membership->user?->name ?? '']) }}
                </p>

                <form method="POST" action="{{ route('staff.invitation.accept', ['token' => $token]) }}" class="mt-6 space-y-5">
                    @csrf

                    <div>
                        <label for="password" class="block text-sm font-medium text-lba-navy">
                            {{ __('lba.staff_invitation.password') }}
                        </label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                            class="mt-1 block w-full rounded-lg border border-lba-line px-3 py-2 text-sm shadow-sm focus:border-lba-teal focus:outline-none focus:ring-1 focus:ring-lba-teal">
                        @error('password')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-lba-navy">
                            {{ __('lba.staff_invitation.password_confirmation') }}
                        </label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                            class="mt-1 block w-full rounded-lg border border-lba-line px-3 py-2 text-sm shadow-sm focus:border-lba-teal focus:outline-none focus:ring-1 focus:ring-lba-teal">
                    </div>

                    <p class="text-xs text-lba-ink">{{ __('lba.staff_invitation.password_hint') }}</p>

                    <button type="submit" class="lba-btn lba-btn--primary w-full justify-center">
                        {{ __('lba.staff_invitation.submit') }}
                    </button>
                </form>
            @elseif ($canAccept)
                <p class="mt-4 text-sm text-lba-ink">
                    {{ __('lba.staff_invitation.existing_intro', ['name' => $membership->user?->name ?? '']) }}
                </p>

                @error('password')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror

                <form method="POST" action="{{ route('staff.invitation.accept', ['token' => $token]) }}" class="mt-6">
                    @csrf
                    <button type="submit" class="lba-btn lba-btn--primary w-full justify-center">
                        {{ __('lba.staff_invitation.accept') }}
                    </button>
                </form>
            @else
                <p class="mt-4 text-sm text-lba-ink">
                    {{ __('lba.staff_invitation.existing_intro', ['name' => $membership->user?->name ?? '']) }}
                </p>

                @error('password')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror

                <p class="mt-4 text-sm text-lba-ink">
                    {{ __('lba.staff_invitation.sign_in_required', ['email' => $membership->user?->email ?? '']) }}
                </p>

                <a href="{{ route('login') }}" class="mt-6 inline-flex lba-btn lba-btn--primary w-full justify-center">
                    {{ __('lba.staff_invitation.sign_in') }}
                </a>
            @endif
        </div>

        <p class="mt-6 text-center text-xs text-lba-ink">{{ __('lba.staff_invitation.footnote') }}</p>
    </main>
</body>
</html>
