{{--
    WS6.D — "Ubah Kata Sandi" / "Change password" / "Tukar Kata Laluan".

    The identity changes ONLY its own password. The current password is
    required and verified server-side; the new value is validated with
    Laravel's password rules and never echoed back.
--}}
<x-filament-panels::page>
    <form wire:submit="changePassword" class="max-w-lg space-y-5">
        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="current_password">
                {{ __('lba.password_change.current') }}
            </label>
            <input
                id="current_password"
                type="password"
                wire:model="current_password"
                autocomplete="current-password"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            >
            @error('current_password')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="password">
                {{ __('lba.password_change.new') }}
            </label>
            <input
                id="password"
                type="password"
                wire:model="password"
                autocomplete="new-password"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            >
            @error('password')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="password_confirmation">
                {{ __('lba.password_change.confirmation') }}
            </label>
            <input
                id="password_confirmation"
                type="password"
                wire:model="password_confirmation"
                autocomplete="new-password"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
            >
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('lba.password_change.hint') }}</p>

        <x-filament::button type="submit">
            {{ __('lba.password_change.submit') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
