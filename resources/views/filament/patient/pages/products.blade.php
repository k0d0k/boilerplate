{{--
    WS3 — "Produk".

    The patient-facing catalog of the verified tenant's ACTIVE products. Every
    price is quoted in ONE explicitly selected currency; a product that cannot
    be priced in that currency is shown as unavailable. Placing an order creates
    it for the acting identity's OWN patient record (server-enforced) through
    the shared order service, then lands on "Pesanan Saya" to pay it.
--}}
<x-filament-panels::page>
    @php($branchOptions = $this->branchOptions())
    @php($products = $this->products())

    @if ($branchOptions === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.products.no_branch') }}
        </p>
    @elseif ($products->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.products.empty') }}
        </p>
    @else
        <form wire:submit="submit" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="currency">
                        {{ __('lba.patient.products.currency') }}
                    </label>
                    <select
                        id="currency"
                        wire:model.live="currency"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    >
                        @foreach ($this->currencyOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('currency')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-950 dark:text-white" for="branchId">
                        {{ __('lba.patient.products.branch') }}
                    </label>
                    <select
                        id="branchId"
                        wire:model.live="branchId"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                    >
                        <option value="">{{ __('lba.patient.products.choose_branch') }}</option>
                        @foreach ($branchOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('branchId')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <ul role="list" class="space-y-3">
                @foreach ($products as $product)
                    @php($price = $this->priceLabel($product))
                    <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-semibold text-gray-950 dark:text-white">
                                    {{ $product->name }}
                                </p>

                                @if (filled($product->sku))
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $product->sku }}
                                    </p>
                                @endif
                            </div>

                            <div class="shrink-0 text-right">
                                @if ($price === null)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('lba.patient.products.unavailable') }}
                                    </span>
                                @else
                                    <p class="font-semibold text-gray-950 dark:text-white">
                                        {{ $price }}
                                    </p>
                                    <label
                                        class="mt-2 block text-xs text-gray-500 dark:text-gray-400"
                                        for="quantity-{{ $product->getKey() }}"
                                    >
                                        {{ __('lba.patient.products.quantity') }}
                                    </label>
                                    <input
                                        id="quantity-{{ $product->getKey() }}"
                                        type="number"
                                        min="0"
                                        wire:model="quantities.{{ $product->getKey() }}"
                                        class="mt-1 w-24 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900"
                                    >
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @error('quantities')
                <p class="text-sm text-danger-600">{{ $message }}</p>
            @enderror

            <div>
                <label class="block text-sm font-medium text-gray-950 dark:text-white" for="notes">
                    {{ __('lba.patient.products.notes') }}
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
                {{ __('lba.patient.products.submit') }}
            </x-filament::button>
        </form>
    @endif
</x-filament-panels::page>
