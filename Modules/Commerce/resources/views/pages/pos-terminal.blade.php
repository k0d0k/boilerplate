@php
    $products = $this->products();
    $branchOptions = $this->branchOptions();
    $patientOptions = $this->patientOptions();
    $canCheckout = $this->canCheckout();
@endphp

<x-filament-panels::page>
    <style>
        .lba-pos { --lba-navy: #14263d; --lba-navy-2: #1c3550; --lba-teal: #087f80; --lba-teal-2: #0aa5a6; --lba-bright: #7fe3e0; --lba-line: rgba(255,255,255,0.12); --lba-fg: rgba(255,255,255,0.94); --lba-muted: rgba(255,255,255,0.6); display: grid; gap: 1rem; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); }
        @media (max-width: 1024px) { .lba-pos { grid-template-columns: minmax(0, 1fr); } }
        .lba-pos__panel { background: var(--lba-navy); border: 1px solid var(--lba-line); border-radius: 0.75rem; padding: 1rem; color: var(--lba-fg); display: flex; flex-direction: column; gap: 0.875rem; }
        .lba-pos__eyebrow { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: var(--lba-bright); }
        .lba-pos__title { font-size: 1.05rem; font-weight: 700; color: #fff; }
        .lba-pos__grid { display: grid; gap: 0.5rem; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); }
        .lba-pos__card { border: 1px solid var(--lba-line); background: var(--lba-navy-2); border-radius: 0.625rem; padding: 0.625rem; text-align: left; cursor: pointer; display: flex; flex-direction: column; gap: 0.25rem; }
        .lba-pos__card:hover { border-color: var(--lba-teal-2); }
        .lba-pos__card-name { font-size: 0.8125rem; font-weight: 600; color: var(--lba-fg); }
        .lba-pos__card-sku { font-size: 0.6875rem; color: var(--lba-muted); font-family: ui-monospace, Menlo, monospace; }
        .lba-pos__card-price { font-size: 0.8125rem; font-weight: 700; color: var(--lba-bright); }
        .lba-pos__field { display: flex; flex-direction: column; gap: 0.25rem; }
        .lba-pos__label { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--lba-muted); }
        .lba-pos__input { width: 100%; border-radius: 0.5rem; border: 1px solid var(--lba-line); background: rgba(0,0,0,0.25); color: var(--lba-fg); padding: 0.5rem 0.625rem; font-size: 0.8125rem; }
        .lba-pos__row { display: grid; gap: 0.625rem; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); }
        .lba-pos__table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        .lba-pos__table th { text-align: left; padding: 0.375rem 0.5rem; border-bottom: 1px solid var(--lba-line); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--lba-muted); }
        .lba-pos__table td { padding: 0.375rem 0.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .lba-pos__qty { width: 3.5rem; }
        .lba-pos__totals { display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.8125rem; }
        .lba-pos__total-row { display: flex; justify-content: space-between; color: var(--lba-muted); }
        .lba-pos__total-row--grand { color: #fff; font-weight: 700; font-size: 1rem; border-top: 1px solid var(--lba-line); padding-top: 0.5rem; }
        .lba-pos__btn { border-radius: 0.5rem; padding: 0.6rem 0.875rem; font-size: 0.8125rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
        .lba-pos__btn--primary { background: var(--lba-teal); color: #fff; }
        .lba-pos__btn--primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .lba-pos__btn--ghost { background: transparent; border-color: var(--lba-line); color: var(--lba-fg); }
        .lba-pos__btn--link { background: transparent; color: #f0abfc; padding: 0; }
        .lba-pos__empty { font-size: 0.8125rem; color: var(--lba-muted); }
        .lba-pos__notice { padding: 0.625rem 0.75rem; border-radius: 0.5rem; font-size: 0.8125rem; border: 1px solid rgba(127,227,224,0.4); background: rgba(127,227,224,0.1); color: var(--lba-bright); }
    </style>

    <div class="lba-pos">
        <div class="lba-pos__panel">
            <div>
                <p class="lba-pos__eyebrow">{{ __('lba.commerce.pos.eyebrow') }}</p>
                <p class="lba-pos__title">{{ __('lba.commerce.pos.catalog') }}</p>
            </div>

            <div class="lba-pos__row">
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.branch') }}</label>
                    <select class="lba-pos__input" wire:model.live="branchId">
                        @foreach ($branchOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.currency') }}</label>
                    <select class="lba-pos__input" wire:model.live="currency">
                        @foreach ($this->currencyOptions() as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.search') }}</label>
                    <input type="text" class="lba-pos__input" placeholder="{{ __('lba.commerce.pos.search_placeholder') }}" wire:model.live.debounce.300ms="search">
                </div>
            </div>

            @if ($products->isEmpty())
                <p class="lba-pos__empty">{{ __('lba.commerce.pos.no_products') }}</p>
            @else
                <div class="lba-pos__grid">
                    @foreach ($products as $product)
                        @php $price = $product->priceFor($currency); @endphp
                        <button type="button" class="lba-pos__card" wire:click="addProduct('{{ $product->getKey() }}')" @disabled($price === null)>
                            <span class="lba-pos__card-name">{{ $product->name }}</span>
                            <span class="lba-pos__card-sku">{{ $product->sku ?? '—' }}</span>
                            <span class="lba-pos__card-price">{{ $price !== null ? $this->format($price) : __('lba.commerce.pos.unpriced_short') }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="lba-pos__panel">
            <div>
                <p class="lba-pos__eyebrow">{{ __('lba.commerce.pos.cart') }}</p>
                @if ($lastOrderNumber)
                    <p class="lba-pos__notice">{{ __('lba.commerce.pos.last_order', ['number' => $lastOrderNumber]) }}</p>
                @endif
                @if ($lastRedirectUrl)
                    <p class="lba-pos__notice">
                        {{ __('lba.commerce.pos.provider_ready') }}
                        <a href="{{ $lastRedirectUrl }}" target="_blank" rel="noopener">{{ $lastRedirectUrl }}</a>
                    </p>
                @endif
            </div>

            @if ($lines === [])
                <p class="lba-pos__empty">{{ __('lba.commerce.pos.cart_empty') }}</p>
            @else
                <table class="lba-pos__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.commerce.pos.item') }}</th>
                            <th>{{ __('lba.commerce.pos.qty') }}</th>
                            <th>{{ __('lba.commerce.pos.line_total') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $key => $line)
                            <tr wire:key="pos-line-{{ $key }}">
                                <td>
                                    {{ $line['name'] }}
                                    <div class="lba-pos__card-sku">{{ $this->format($line['unit_price']) }}</div>
                                </td>
                                <td>
                                    <input type="number" min="0" class="lba-pos__input lba-pos__qty"
                                        value="{{ $line['quantity'] }}"
                                        wire:change="setQuantity('{{ $key }}', $event.target.value)">
                                </td>
                                <td>{{ $this->format($line['unit_price'] * $line['quantity']) }}</td>
                                <td><button type="button" class="lba-pos__btn lba-pos__btn--link" wire:click="removeLine('{{ $key }}')">×</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <div class="lba-pos__row">
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.discount') }}</label>
                    <input type="number" min="0" step="any" class="lba-pos__input" wire:model.live="discountTotal">
                </div>
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.tax') }}</label>
                    <input type="number" min="0" step="any" class="lba-pos__input" wire:model.live="taxTotal">
                </div>
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.method') }}</label>
                    <select class="lba-pos__input" wire:model.live="paymentMethod">
                        @foreach ($this->methodOptions() as $method => $label)
                            <option value="{{ $method }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($paymentMethod === \Modules\Commerce\Filament\Pages\PosTerminal::METHOD_PROVIDER)
                    <div class="lba-pos__field">
                        <label class="lba-pos__label">{{ __('lba.commerce.pos.provider') }}</label>
                        <select class="lba-pos__input" wire:model.live="provider">
                            <option value="">{{ __('lba.commerce.pos.provider_none') }}</option>
                            @foreach ($this->providerOptions() as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            <div class="lba-pos__totals">
                <div class="lba-pos__total-row"><span>{{ __('lba.commerce.pos.subtotal') }}</span><span>{{ $this->format($this->subtotal()) }}</span></div>
                <div class="lba-pos__total-row lba-pos__total-row--grand"><span>{{ __('lba.commerce.pos.grand_total') }}</span><span>{{ $this->format($this->grandTotal()) }}</span></div>
            </div>

            <div class="lba-pos__row">
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.patient') }}</label>
                    <select class="lba-pos__input" wire:model="patientId">
                        <option value="">{{ __('lba.commerce.pos.patient_none') }}</option>
                        @foreach ($patientOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.customer') }}</label>
                    <input type="text" class="lba-pos__input" wire:model="customerName">
                </div>
            </div>

            <div class="lba-pos__row">
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.email') }}</label>
                    <input type="email" class="lba-pos__input" wire:model="customerEmail">
                </div>
                <div class="lba-pos__field">
                    <label class="lba-pos__label">{{ __('lba.commerce.pos.phone') }}</label>
                    <input type="text" class="lba-pos__input" wire:model="customerPhone">
                </div>
            </div>

            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button type="button" class="lba-pos__btn lba-pos__btn--primary" wire:click="checkout" @disabled(! $canCheckout)>
                    {{ __('lba.commerce.pos.checkout') }}
                </button>
                <button type="button" class="lba-pos__btn lba-pos__btn--ghost" wire:click="clearCart" @disabled($lines === [])>
                    {{ __('lba.commerce.pos.clear') }}
                </button>
            </div>
        </div>
    </div>
</x-filament-panels::page>
