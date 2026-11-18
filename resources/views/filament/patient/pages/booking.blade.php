{{--
    Phase 8A — "Booking Pemeriksaan".

    A self-service booking form. The branch list is limited to the verified
    tenant's active branches, and the booking is always created for the acting
    identity's own patient record (server-enforced).

    WS7.D — when the patient optionally chooses a verified practitioner, the
    available slots of that practitioner's own practice schedule are offered and
    the booking is serialised server-side. Leaving the practitioner empty keeps
    the "clinic assigns" fallback; only non-clinical scheduling data is shown.
--}}
<x-filament-panels::page>
    @php($branchOptions = $this->branchOptions())
    @php($clinicianOptions = $this->clinicianOptions())
    @php($slotOptions = $this->slotOptions())
    @php($hasSlotPicker = filled($this->practitionerId) && $slotOptions !== [])

    @if ($branchOptions === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.booking.no_branch') }}
        </p>
    @else
        <form wire:submit="submit" class="space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-950 dark:text-white" for="branchId">
                    {{ __('lba.patient.booking.branch') }}
                </label>
                <select
                    id="branchId"
                    wire:model.live="branchId"
                    class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                >
                    <option value="">{{ __('lba.patient.booking.choose_branch') }}</option>
                    @foreach ($branchOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                @error('branchId')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-950 dark:text-white" for="serviceType">
                    {{ __('lba.patient.booking.service') }}
                </label>
                <select
                    id="serviceType"
                    wire:model.live="serviceType"
                    class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                >
                    @foreach ($this->serviceOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('serviceType')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            @if ($this->requiresClinician() && $clinicianOptions !== [])
                <div class="space-y-5 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('lba.patient.booking.clinician_hint') }}
                    </p>

                    <div>
                        <label class="block text-sm font-medium text-gray-950 dark:text-white" for="practitionerId">
                            {{ __('lba.patient.booking.clinician') }}
                        </label>
                        <select
                            id="practitionerId"
                            wire:model.live="practitionerId"
                            class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                        >
                            <option value="">{{ __('lba.patient.booking.any_clinician') }}</option>
                            @foreach ($clinicianOptions as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('practitionerId')
                            <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($hasSlotPicker)
                        <div>
                            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="slotStartsAt">
                                {{ __('lba.patient.booking.choose_slot') }}
                            </label>
                            <select
                                id="slotStartsAt"
                                wire:model="startsAt"
                                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                            >
                                @foreach ($slotOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @elseif (filled($this->practitionerId))
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('lba.patient.booking.no_slots') }}
                        </p>
                    @endif
                </div>
            @endif

            {{-- The manual time input is the fallback when no clinician slot is
                 being picked; it is hidden once a slot picker is shown so the
                 two never fight over the same deferred `startsAt` value. --}}
            @unless ($hasSlotPicker)
                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="startsAt">
                        {{ __('lba.patient.booking.starts_at') }}
                    </label>
                    <input
                        id="startsAt"
                        type="datetime-local"
                        wire:model="startsAt"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    >
                    @error('startsAt')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>
            @endunless

            <div>
                <label class="block text-sm font-medium text-gray-950 dark:text-white" for="notes">
                    {{ __('lba.patient.booking.notes') }}
                </label>
                <textarea
                    id="notes"
                    wire:model="notes"
                    rows="3"
                    class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                ></textarea>
                @error('notes')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <x-filament::button type="submit">
                {{ __('lba.patient.booking.submit') }}
            </x-filament::button>
        </form>
    @endif
</x-filament-panels::page>
