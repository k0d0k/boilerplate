<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('demomodule::demomodule.page.section_heading') }}
        </x-slot>

        <x-slot name="description">
            {{ __('demomodule::demomodule.page.section_description') }}
        </x-slot>

        <div class="flex flex-col gap-4">
            <p>{{ __('demomodule::demomodule.page.body') }}</p>

            <div>
                <x-filament::button
                    type="button"
                    color="primary"
                    wire:click="performProtectedAction"
                >
                    {{ __('demomodule::demomodule.page.action') }}
                </x-filament::button>
            </div>

            @if ($protectedActionsPerformed > 0)
                <p>
                    {{ __('demomodule::demomodule.page.action_result', ['count' => $protectedActionsPerformed]) }}
                </p>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
