@php
    $wallet = $this->wallet();
    $available = $this->availableCredits();
    $reserved = $this->reservedCredits();
    $consumed = $this->consumedCredits();
    $billingEnabled = $this->billingEnabled();
    $insufficient = $this->hasInsufficientBalance();
    $usage = $this->usageRecords();
    $ledger = $this->ledgerEntries();
    $tenantId = $this->tenantId();
@endphp

<x-filament-panels::page>
    <style>
        .lba-wallet {
            --lba-wallet-bg: #0c1725;
            --lba-wallet-panel: #0f1d2f;
            --lba-wallet-line: rgba(255, 255, 255, 0.10);
            --lba-wallet-fg: rgba(255, 255, 255, 0.92);
            --lba-wallet-muted: rgba(255, 255, 255, 0.55);
            --lba-wallet-teal: #0aa5a6;
            --lba-wallet-bright: #7fe3e0;
            background: var(--lba-wallet-bg);
            border: 1px solid var(--lba-wallet-line);
            border-radius: 0.75rem;
            padding: 1.25rem;
            color: var(--lba-wallet-fg);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .lba-wallet__eyebrow { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: var(--lba-wallet-bright); }
        .lba-wallet__metrics { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); }
        .lba-wallet__metric { padding: 1rem; border: 1px solid var(--lba-wallet-line); border-radius: 0.625rem; background: var(--lba-wallet-panel); }
        .lba-wallet__metric-label { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--lba-wallet-muted); }
        .lba-wallet__metric-value { margin-top: 0.375rem; font-size: 1.75rem; font-weight: 700; color: #fff; line-height: 1.1; }
        .lba-wallet__metric--accent .lba-wallet__metric-value { color: var(--lba-wallet-bright); }
        .lba-wallet__notice { padding: 0.75rem 0.875rem; border-radius: 0.625rem; font-size: 0.8125rem; }
        .lba-wallet__notice--warn { border: 1px solid rgba(255, 209, 102, 0.5); background: rgba(255, 209, 102, 0.12); color: #ffd166; }
        .lba-wallet__notice--info { border: 1px solid var(--lba-wallet-line); background: rgba(255, 255, 255, 0.04); color: var(--lba-wallet-muted); }
        .lba-wallet__block { padding: 0.875rem; border: 1px solid var(--lba-wallet-line); border-radius: 0.625rem; background: var(--lba-wallet-panel); }
        .lba-wallet__block-title { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--lba-wallet-muted); margin-bottom: 0.625rem; }
        .lba-wallet__table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        .lba-wallet__table th { text-align: left; padding: 0.5rem 0.625rem; border-bottom: 1px solid var(--lba-wallet-line); font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lba-wallet-muted); }
        .lba-wallet__table td { padding: 0.5rem 0.625rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); color: var(--lba-wallet-fg); overflow-wrap: anywhere; }
        .lba-wallet__table tr:last-child td { border-bottom: none; }
        .lba-wallet__ref { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; color: var(--lba-wallet-muted); }
        .lba-wallet__empty { font-size: 0.8125rem; color: var(--lba-wallet-muted); }
        .lba-wallet__badge { display: inline-flex; align-items: center; padding: 0.0625rem 0.4rem; border-radius: 999px; font-size: 0.625rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; }
        .lba-wallet__badge--reserve { background: rgba(127, 227, 224, 0.18); color: var(--lba-wallet-bright); }
        .lba-wallet__badge--capture { background: rgba(232, 121, 249, 0.18); color: #f0abfc; }
        .lba-wallet__badge--release { background: rgba(255, 209, 102, 0.18); color: #ffd166; }
        .lba-wallet__badge--grant { background: rgba(74, 222, 128, 0.18); color: #86efac; }
        .lba-wallet__badge--adjust { background: rgba(255, 255, 255, 0.12); color: var(--lba-wallet-muted); }
    </style>

    <div class="lba-wallet">
        <div>
            <p class="lba-wallet__eyebrow">{{ __('lba.wallet.eyebrow') }}</p>
            @if ($tenantId)
                <p class="lba-wallet__block-title" style="margin-top: 0.375rem;">{{ __('lba.wallet.tenant') }}: <span class="lba-wallet__ref">{{ $tenantId }}</span></p>
            @endif
        </div>

        <div class="lba-wallet__metrics">
            <div class="lba-wallet__metric lba-wallet__metric--accent">
                <p class="lba-wallet__metric-label">{{ __('lba.wallet.available') }}</p>
                <p class="lba-wallet__metric-value">{{ number_format($available) }}</p>
            </div>
            <div class="lba-wallet__metric">
                <p class="lba-wallet__metric-label">{{ __('lba.wallet.reserved') }}</p>
                <p class="lba-wallet__metric-value">{{ number_format($reserved) }}</p>
            </div>
            <div class="lba-wallet__metric">
                <p class="lba-wallet__metric-label">{{ __('lba.wallet.consumed') }}</p>
                <p class="lba-wallet__metric-value">{{ number_format($consumed) }}</p>
            </div>
        </div>

        @unless ($billingEnabled)
            <div class="lba-wallet__notice lba-wallet__notice--info">
                {{ __('lba.wallet.billing_disabled') }}
            </div>
        @endunless

        @if ($insufficient && $billingEnabled)
            <div class="lba-wallet__notice lba-wallet__notice--warn">
                {{ __('lba.wallet.insufficient') }}
            </div>
        @endif

        <div class="lba-wallet__block">
            <p class="lba-wallet__block-title">{{ __('lba.wallet.usage_title') }}</p>
            @if ($usage->isEmpty())
                <p class="lba-wallet__empty">{{ __('lba.wallet.usage_empty') }}</p>
            @else
                <table class="lba-wallet__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.wallet.col_time') }}</th>
                            <th>{{ __('lba.wallet.col_job') }}</th>
                            <th>{{ __('lba.wallet.col_model') }}</th>
                            <th>{{ __('lba.wallet.col_units') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($usage as $record)
                            <tr>
                                <td>{{ $record->recorded_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="lba-wallet__ref">{{ $record->job_reference }}</td>
                                <td>{{ $record->provider }}{{ $record->model_name ? ' · '.$record->model_name : '' }}{{ $record->model_version ? ' ('.$record->model_version.')' : '' }}</td>
                                <td>{{ number_format((int) $record->credit_units) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="lba-wallet__block">
            <p class="lba-wallet__block-title">{{ __('lba.wallet.ledger_title') }}</p>
            @if ($ledger->isEmpty())
                <p class="lba-wallet__empty">{{ __('lba.wallet.ledger_empty') }}</p>
            @else
                <table class="lba-wallet__table">
                    <thead>
                        <tr>
                            <th>{{ __('lba.wallet.col_time') }}</th>
                            <th>{{ __('lba.wallet.col_action') }}</th>
                            <th>{{ __('lba.wallet.col_job') }}</th>
                            <th>{{ __('lba.wallet.col_units') }}</th>
                            <th>{{ __('lba.wallet.col_balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ledger as $entry)
                            <tr>
                                <td>{{ $entry->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>
                                    <span class="lba-wallet__badge lba-wallet__badge--{{ $entry->action }}">{{ $entry->action }}</span>
                                </td>
                                <td class="lba-wallet__ref">{{ $entry->job_reference ?? '—' }}</td>
                                <td>{{ number_format((int) $entry->credit_units) }}</td>
                                <td>{{ number_format((int) $entry->available_after) }} / {{ number_format((int) $entry->reserved_after) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
