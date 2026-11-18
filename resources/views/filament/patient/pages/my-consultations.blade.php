{{--
    Workstream 2 — "Konsultasi Saya".

    A patient books a HUMAN expert session at one active branch of the verified
    tenant and reviews their own history. Self-only: the query is always scoped
    to the signed-in patient's record, and the booking always targets that same
    record (server-enforced).
--}}
<x-filament-panels::page>
    @php($consultations = $this->consultations())
    @php($branchOptions = $this->branchOptions())
    @php($clinicianOptions = $this->clinicianOptions())
    @php($slotOptions = $this->slotOptions())
    @php($hasSlotPicker = filled($this->practitionerId) && $slotOptions !== [])

    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
        <p class="font-semibold text-gray-950 dark:text-white">
            {{ __('lba.patient.consultations.book_title') }}
        </p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.consultations.book_subtitle') }}
        </p>

        @if ($branchOptions === [])
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('lba.patient.booking.no_branch') }}
            </p>
        @else
            <form wire:submit="submit" class="mt-4 space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="branchId">
                        {{ __('lba.patient.consultations.branch') }}
                    </label>
                    <select
                        id="branchId"
                        wire:model.live="branchId"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    >
                        <option value="">{{ __('lba.patient.consultations.choose_branch') }}</option>
                        @foreach ($branchOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('branchId')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="channel">
                        {{ __('lba.patient.consultations.channel_label') }}
                    </label>
                    <select
                        id="channel"
                        wire:model="channel"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    >
                        @foreach ($this->channelOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('channel')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                @if ($clinicianOptions !== [])
                    <div class="space-y-5 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('lba.patient.consultations.clinician_hint') }}
                        </p>

                        <div>
                            <label class="block text-sm font-medium text-gray-950 dark:text-white" for="practitionerId">
                                {{ __('lba.patient.consultations.practitioner') }}
                            </label>
                            <select
                                id="practitionerId"
                                wire:model.live="practitionerId"
                                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                            >
                                <option value="">{{ __('lba.patient.consultations.any_practitioner') }}</option>
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
                                    {{ __('lba.patient.consultations.choose_slot') }}
                                </label>
                                <select
                                    id="slotStartsAt"
                                    wire:model="scheduledAt"
                                    class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                                >
                                    @foreach ($slotOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @elseif (filled($this->practitionerId))
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ __('lba.patient.consultations.no_slots') }}
                            </p>
                        @endif
                    </div>
                @endif

                @unless ($hasSlotPicker)
                    <div>
                        <label class="block text-sm font-medium text-gray-950 dark:text-white" for="scheduledAt">
                            {{ __('lba.patient.consultations.scheduled_at') }}
                        </label>
                        <input
                            id="scheduledAt"
                            type="datetime-local"
                            wire:model="scheduledAt"
                            class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                        >
                        @error('scheduledAt')
                            <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endunless

                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="reason">
                        {{ __('lba.patient.consultations.reason') }}
                    </label>
                    <textarea
                        id="reason"
                        wire:model="reason"
                        rows="3"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    ></textarea>
                    @error('reason')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <x-filament::button type="submit">
                    {{ __('lba.patient.consultations.book') }}
                </x-filament::button>
            </form>
        @endif
    </div>

    @if ($consultations->isEmpty())
        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.consultations.empty') }}
        </p>
    @else
        <ul role="list" class="mt-6 space-y-3">
            @foreach ($consultations as $consultation)
                <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold text-gray-950 dark:text-white">
                                {{ $consultation->scheduled_at?->translatedFormat('d M Y · H:i') ?? '—' }}
                            </p>

                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $consultation->branch?->name ?? '—' }}
                                <span aria-hidden="true">·</span>
                                {{ $this->channelLabel($consultation->channel) }}
                            </p>
                        </div>

                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium">
                            {{ $this->statusLabel($consultation->status) }}
                        </span>
                    </div>

                    @php($tariff = $this->tariffLabel($consultation))
                    @if ($tariff !== null)
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('lba.patient.consultations.tariff') }}
                            <span class="font-semibold">{{ $tariff }}</span>
                        </p>
                    @endif

                    @if ($this->canCancel($consultation))
                        <div class="mt-3">
                            <x-filament::button
                                color="danger"
                                size="sm"
                                wire:click="cancel('{{ $consultation->getKey() }}')"
                                wire:confirm="{{ __('lba.patient.consultations.confirm_cancel') }}"
                            >
                                {{ __('lba.patient.consultations.cancel') }}
                            </x-filament::button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4 rounded-xl border border-gray-200 p-4 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            <p>{{ __($this->cancellableBeforeStart()
                ? 'lba.patient.consultations.policy.cancellable'
                : 'lba.patient.consultations.policy.not_cancellable') }}</p>
            <p class="mt-1">{{ __($this->refundable()
                ? 'lba.patient.consultations.policy.refundable'
                : 'lba.patient.consultations.policy.not_refundable') }}</p>
        </div>
    @endif
</x-filament-panels::page>
