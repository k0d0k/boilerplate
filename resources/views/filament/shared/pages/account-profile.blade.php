{{--
    WS9.E — "Profil Akun" / "Account profile" / account profile.

    The identity maintains ONLY its own LOGIN account (display name, login email,
    phone, description). This is NOT the patient chart: the patient panel keeps a
    separate page for patient data. Changing the login email requires the current
    password and a verification of the new address before it is used.
--}}
<x-filament-panels::page>
    @php($pendingEmail = $this->pendingEmail())

    @if ($pendingEmail)
        <div class="mb-4 rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200">
            {{ __('lba.account.pending_notice', ['email' => $pendingEmail]) }}
        </div>
    @endif

    <form wire:submit="save" class="max-w-lg space-y-5">
        <div>
            <span class="block text-sm font-medium text-gray-950 dark:text-white">
                {{ __('lba.account.photo') }}
            </span>
            <div class="mt-2 flex items-center gap-4">
                @if ($this->photoUrl())
                    <img
                        src="{{ $this->photoUrl() }}"
                        alt=""
                        class="h-16 w-16 rounded-full object-cover ring-1 ring-gray-200 dark:ring-white/10"
                    >
                @else
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-600 text-lg font-semibold text-white">
                        {{ mb_strtoupper(mb_substr((string) $name, 0, 1)) }}
                    </span>
                @endif

                <div class="space-y-2">
                    <input
                        id="account-photo"
                        type="file"
                        wire:model="photo"
                        accept="image/png,image/jpeg,image/webp"
                        class="block text-sm text-gray-700 dark:text-gray-200"
                    >
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('lba.account.photo_help', ['max' => (int) config('lba.accounts.photo_max_kilobytes', 2048)]) }}</p>

                    @if ($this->hasPhoto())
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" wire:model="remove_photo" class="rounded border-gray-300 dark:border-white/10">
                            {{ __('lba.account.remove_photo') }}
                        </label>
                    @endif
                </div>
            </div>
            @error('photo')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="account-name">
                {{ __('lba.account.name') }}
            </label>
            <input
                id="account-name"
                type="text"
                wire:model="name"
                autocomplete="name"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            >
            @error('name')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="account-email">
                {{ __('lba.account.email') }}
            </label>
            <input
                id="account-email"
                type="email"
                wire:model="email"
                autocomplete="username"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            >
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('lba.account.email_help') }}</p>
            @error('email')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="account-phone">
                {{ __('lba.account.phone') }}
            </label>
            <input
                id="account-phone"
                type="text"
                wire:model="phone"
                autocomplete="tel"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            >
            @error('phone')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="account-description">
                {{ __('lba.account.description') }}
            </label>
            <textarea
                id="account-description"
                rows="3"
                wire:model="description"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            ></textarea>
            @error('description')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="account-address">
                {{ __('lba.account.address') }}
            </label>
            <textarea
                id="account-address"
                rows="2"
                wire:model="address"
                autocomplete="street-address"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            ></textarea>
            @error('address')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="account-current-password">
                {{ __('lba.account.current_password') }}
            </label>
            <input
                id="account-current-password"
                type="password"
                wire:model="current_password"
                autocomplete="current-password"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            >
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('lba.account.current_password_help') }}</p>
            @error('current_password')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-4">
            <x-filament::button type="submit">
                {{ __('lba.account.submit') }}
            </x-filament::button>

            <a
                href="{{ $this->changePasswordUrl() }}"
                class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ __('lba.account.change_password_link') }}
            </a>
        </div>
    </form>
</x-filament-panels::page>
