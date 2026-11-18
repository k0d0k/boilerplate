{{--
    Phase 8A — "Profil".

    The patient's clinical-of-record identity (name, patient number) is shown
    read-only; only the contact fields are self-editable.
--}}
<x-filament-panels::page>
    <div class="space-y-5">
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('lba.patient.profile.full_name') }}
                </dt>
                <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                    {{ $this->fullName() ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('lba.patient.profile.patient_number') }}
                </dt>
                <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                    {{ $this->patientNumber() ?? '—' }}
                </dd>
            </div>
        </dl>

        <form wire:submit="save" class="space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-950 dark:text-white" for="phone">
                    {{ __('lba.patient.profile.phone') }}
                </label>
                <input
                    id="phone"
                    type="text"
                    wire:model="phone"
                    class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                >
                @error('phone')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-950 dark:text-white" for="email">
                    {{ __('lba.patient.profile.email') }}
                </label>
                <input
                    id="email"
                    type="email"
                    wire:model="email"
                    class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                >
                @error('email')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-950 dark:text-white" for="address">
                    {{ __('lba.patient.profile.address') }}
                </label>
                <input
                    id="address"
                    type="text"
                    wire:model="address"
                    class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                >
                @error('address')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="city">
                        {{ __('lba.patient.profile.city') }}
                    </label>
                    <input
                        id="city"
                        type="text"
                        wire:model="city"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    >
                    @error('city')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="region">
                        {{ __('lba.patient.profile.region') }}
                    </label>
                    <input
                        id="region"
                        type="text"
                        wire:model="region"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    >
                    @error('region')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="country">
                        {{ __('lba.patient.profile.country') }}
                    </label>
                    <input
                        id="country"
                        type="text"
                        maxlength="2"
                        wire:model="country"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    >
                    @error('country')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <x-filament::button type="submit">
                {{ __('lba.patient.profile.submit') }}
            </x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
