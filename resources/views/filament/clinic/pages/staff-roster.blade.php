{{--
    WS6.E — "Pengguna & Staf" roster.

    A read-only, tenant-scoped view of the verified tenant's staff, grouped by
    the five understandable user categories. Nothing here can assign a role:
    provisioning stays a Principal capability, so a clinic administrator can
    never escalate a role or reach another tenant from this screen.
--}}
<x-filament-panels::page>
    <div class="space-y-6">
        @foreach ($this->getGroups() as $key => $group)
            <x-filament::section
                :heading="$group['label']"
                :description="$group['description']"
            >
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $group['safety'] }}
                </p>

                @if (count($group['members']) === 0)
                    <p class="mt-3 text-sm text-gray-400">
                        {{ __('lba.user_categories.empty_group') }}
                    </p>
                @else
                    <ul class="mt-4 divide-y divide-gray-100 dark:divide-white/5" role="list">
                        @foreach ($group['members'] as $member)
                            <li class="flex items-center justify-between gap-4 py-3">
                                <span class="flex flex-col">
                                    <span class="text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $member['name'] }}
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $member['email'] }}
                                    </span>
                                </span>

                                <span class="flex items-center gap-2">
                                    <x-filament::badge color="gray">
                                        {{ $member['roles'] }}
                                    </x-filament::badge>

                                    <x-filament::badge color="info">
                                        {{ $member['status'] }}
                                    </x-filament::badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
