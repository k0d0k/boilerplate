{{--
    Phase 8A — "Tautkan Akun Klinik".

    A patient presents the single-use invitation code their clinic issued for
    their patient record. Binding never happens by email or phone.
--}}
<x-filament-panels::page>
    <div class="space-y-5">
        @if ($this->isLinked())
            <div class="rounded-lg border border-success-200 bg-success-50 p-4 text-sm text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300">
                {{ __('lba.patient.link.already_linked') }}
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('lba.patient.link.instructions') }}
            </p>

            <form wire:submit="link" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="invitationCode">
                        {{ __('lba.patient.link.code') }}
                    </label>
                    <input
                        id="invitationCode"
                        type="text"
                        autocomplete="off"
                        wire:model="invitationCode"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono dark:border-white/10 dark:bg-gray-900"
                    >
                    @error('invitationCode')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <x-filament::button type="submit">
                    {{ __('lba.patient.link.submit') }}
                </x-filament::button>
            </form>
        @endif
    </div>
</x-filament-panels::page>
