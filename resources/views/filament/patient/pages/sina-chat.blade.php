{{--
    Workstream 2 — patient SINA consultation chat.

    Fail-closed: without the `ai_assistance` consent the chat is not offered;
    without an active session the patient is invited to start one. The send
    action delegates to the SINA chat service which re-derives all server-side
    authorization and billing.
--}}
<x-filament-panels::page>
    @php($session = $this->session())
    @php($messages = $this->messages())

    <div class="rounded-xl border border-gray-200 p-4 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
        {{ __('lba.patient.sina.disclaimer') }}
    </div>

    @if ($this->billingEnabled())
        <div class="mt-4 rounded-xl border border-gray-200 p-4 text-sm text-gray-600 dark:border-white/10 dark:text-gray-300">
            <p class="font-semibold text-gray-950 dark:text-white">{{ __('lba.patient.sina.fee_title') }}</p>
            <p class="mt-1">
                {{ __('lba.patient.sina.fee_unit', [
                    'credits' => $this->creditsPerTurn(),
                    'currency' => $this->creditCurrency(),
                ]) }}
            </p>
            <p class="mt-1">
                {{ __('lba.patient.sina.fee_balance', ['balance' => $this->balanceCredits()]) }}
            </p>
        </div>
    @endif

    @if (! $this->hasConsent())
        <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10">
            <p class="font-semibold text-gray-950 dark:text-white">{{ __('lba.patient.sina.consent_title') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('lba.patient.sina.consent_body') }}</p>

            <div class="mt-3">
                <x-filament::button wire:click="grantConsent">
                    {{ __('lba.patient.sina.consent_action') }}
                </x-filament::button>
            </div>
        </div>
    @elseif ($session === null)
        <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('lba.patient.sina.session_none') }}</p>

            <div class="mt-3">
                <x-filament::button wire:click="startSession">
                    {{ __('lba.patient.sina.start_session') }}
                </x-filament::button>
            </div>
        </div>
    @else
        <ul role="list" class="mt-4 space-y-3">
            @forelse ($messages as $message)
                @php($isAssistant = $message->role === \Modules\Consultations\Models\ConsultationMessage::ROLE_ASSISTANT)
                <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                        {{ $isAssistant
                            ? __('lba.consultations.messages.role_assistant')
                            : __('lba.consultations.messages.role_patient') }}
                    </p>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-950 dark:text-white">{{ $message->content }}</p>

                    @if ($isAssistant && $this->hasGrounding($message))
                        @php($citations = $this->citationsFor($message))
                        <div class="mt-3 border-t border-gray-100 pt-2 dark:border-white/10">
                            @if ($citations === [])
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('lba.patient.sina.source_none') }}</p>
                            @else
                                <p class="text-xs font-semibold text-gray-400">{{ __('lba.patient.sina.sources_title') }}</p>
                                <ul role="list" class="mt-1 space-y-1">
                                    @foreach ($citations as $citation)
                                        <li class="text-xs text-gray-600 dark:text-gray-300">
                                            <a href="{{ $citation['url'] }}" target="_blank" rel="noopener" class="underline">
                                                {{ $citation['title'] }}
                                            </a>
                                            — {{ __('lba.patient.sina.source_page', ['page' => $citation['page']]) }}
                                            @if (! empty($citation['published_on']))
                                                ({{ $citation['published_on'] }})
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('lba.patient.sina.empty') }}</li>
            @endforelse
        </ul>

        <div class="mt-4">
            <textarea
                class="w-full rounded-xl border border-gray-300 p-3 text-sm dark:border-white/10 dark:bg-white/5"
                rows="3"
                wire:model="draft"
                placeholder="{{ __('lba.patient.sina.message_placeholder') }}"
            ></textarea>
            @error('draft') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror

            <div class="mt-3">
                <x-filament::button wire:click="send" wire:loading.attr="disabled">
                    {{ __('lba.patient.sina.send') }}
                </x-filament::button>
            </div>
        </div>
    @endif
</x-filament-panels::page>
