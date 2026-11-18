{{--
    WS3 — "Pesanan Saya".

    Lists only the acting identity's own orders; each shows its explicit
    per-currency total and current status. An OPEN order exposes the sandbox
    providers that can charge its currency right now; paying initiates a hosted
    payment and reveals the redirect link. The order is only settled by a
    verified provider webhook, never here.
--}}
<x-filament-panels::page>
    @php($orders = $this->orders())

    @if ($orders->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.shopping.empty') }}
        </p>
    @else
        <ul role="list" class="space-y-3">
            @foreach ($orders as $order)
                <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold text-gray-950 dark:text-white">
                                {{ $order->order_number }}
                            </p>

                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $order->branch?->name ?? '—' }}
                                <span aria-hidden="true">·</span>
                                {{ $order->placed_at?->translatedFormat('d M Y · H:i') }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="font-semibold text-gray-950 dark:text-white">
                                {{ \Modules\Commerce\Support\Currency::format((float) $order->grand_total, (string) $order->currency) }}
                            </p>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $this->statusLabel($order->status) }}
                            </span>
                        </div>
                    </div>

                    @if ($this->canPay($order))
                        <div class="mt-3 flex flex-wrap items-end gap-3 border-t border-gray-200 pt-3 dark:border-white/10">
                            <div>
                                <label
                                    class="block text-xs text-gray-500 dark:text-gray-400"
                                    for="provider-{{ $order->getKey() }}"
                                >
                                    {{ __('lba.patient.shopping.provider') }}
                                </label>
                                <select
                                    id="provider-{{ $order->getKey() }}"
                                    wire:model="providers.{{ $order->getKey() }}"
                                    class="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                                >
                                    <option value="">{{ __('lba.patient.shopping.choose_provider') }}</option>
                                    @foreach ($this->providerOptions($order) as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <x-filament::button
                                type="button"
                                size="sm"
                                wire:click="pay('{{ $order->getKey() }}')"
                            >
                                {{ __('lba.patient.shopping.pay') }}
                            </x-filament::button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (filled($this->redirectUrl))
        <div class="mt-5 rounded-xl border border-primary-200 bg-primary-50 p-4 dark:border-primary-500/30 dark:bg-primary-500/10">
            <p class="text-sm font-medium text-gray-950 dark:text-white">
                {{ __('lba.patient.shopping.payment_started') }}
            </p>
            <a
                href="{{ $this->redirectUrl }}"
                class="mt-1 inline-block text-sm font-semibold text-primary-600 underline dark:text-primary-400"
            >
                {{ __('lba.patient.shopping.continue_payment') }}
            </a>
        </div>
    @endif
</x-filament-panels::page>
