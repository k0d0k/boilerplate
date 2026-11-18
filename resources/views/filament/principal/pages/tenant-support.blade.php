{{--
    WS9.E — read-only "open tenant" support view.

    Rendered only while a bounded support context is active; the page's
    authorization is re-derived server-side (permission + live, unexpired
    context), so this view is never the boundary. It shows operational registry
    data only — never a clinical record, LBA image or private tenant document.
--}}
<x-filament-panels::page>
    @php($tenant = $this->tenant())

    @if ($tenant)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200">
            {{ __('lba.support.notice') }}
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-base font-semibold text-gray-950 dark:text-white">{{ $tenant->name }}</p>

                @if ($this->expiresAt())
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('lba.support.banner_expires', ['time' => $this->expiresAt()]) }}
                    </p>
                @endif
            </div>

            <x-filament::button
                color="gray"
                icon="heroicon-m-arrow-uturn-left"
                wire:click="closeSupport"
            >
                {{ __('lba.support.back_to_principal') }}
            </x-filament::button>
        </div>

        {{-- Operational posture (central registry only) --}}
        <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.support.overview') }}</p>

            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ($this->overview() as $row)
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $row['label'] }}</dt>
                        <dd class="text-sm text-gray-950 dark:text-white">{{ $row['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Brands --}}
        <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.support.brands') }}</p>

            @php($brands = $this->brands())
            @if ($brands === [])
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lba.support.brands_empty') }}</p>
            @else
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($brands as $brand)
                        <li>
                            {{ $brand['name'] }}
                            @if ($brand['primary'])
                                <span class="ml-1 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                                    {{ __('lba.brands.is_primary') }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Memberships (counts only) --}}
        <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.support.memberships') }}</p>

            @php($counts = $this->membershipCounts())
            @if ($counts === [])
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lba.support.memberships_empty') }}</p>
            @else
                <dl class="mt-3 grid gap-3 sm:grid-cols-4">
                    @foreach ($counts as $status => $count)
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ __('lba.tenants.member_status_'.$status) }}
                            </dt>
                            <dd class="text-sm text-gray-950 dark:text-white">{{ $count }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>

        {{-- Subscription / licence --}}
        <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.support.subscription') }}</p>

            @php($subscription = $this->subscription())
            @if ($subscription === null)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lba.support.subscription_none') }}</p>
            @else
                <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('lba.subscriptions.status') }}</dt>
                        <dd class="text-sm text-gray-950 dark:text-white">{{ $subscription['status'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('lba.support.subscription_active') }}</dt>
                        <dd class="text-sm text-gray-950 dark:text-white">
                            {{ $subscription['active'] ? __('lba.support.yes') : __('lba.support.no') }}
                        </dd>
                    </div>
                    @if ($subscription['period_end'])
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('lba.subscriptions.period_end') }}</dt>
                            <dd class="text-sm text-gray-950 dark:text-white">{{ $subscription['period_end'] }}</dd>
                        </div>
                    @endif
                </dl>
            @endif
        </div>
    @endif
</x-filament-panels::page>
