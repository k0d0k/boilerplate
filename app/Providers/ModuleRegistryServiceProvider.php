<?php

namespace App\Providers;

use App\Platform\ModuleRegistry\Console\SetupClinicCommand;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\ModuleDiscovery;
use App\Platform\ModuleRegistry\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 1B-4 module registry bindings.
 *
 * Binds the registry contract to its in-memory implementation and populates it
 * once per request from the module packages on disk.
 *
 * Discovery happens in register(), not boot(): Filament resolves its panel
 * registry (and therefore evaluates each panel's configuration closure) during
 * its own package boot, which runs before this application provider boots. The
 * panels ask the module registry for their modules while being built, so the
 * registry must already be populated by then.
 */
class ModuleRegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegistersModules::class, ModuleRegistry::class);

        $registry = $this->app->make(RegistersModules::class);

        foreach ((new ModuleDiscovery)->discover() as $manifest) {
            $registry->register($manifest);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SetupClinicCommand::class,
            ]);
        }
    }
}
