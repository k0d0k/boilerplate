<?php

namespace App\Platform\Filament;

use App\Platform\Filament\Contracts\FilamentRegistration;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\ModuleManifest;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * The single Filament plugin every panel installs to host its modules
 * (Phase 1B-4).
 *
 * The three panel providers each add this plugin; none of them contains any
 * module-specific logic, and no module ever edits a panel provider. For each
 * panel the plugin asks the registry which modules are allowed there, then
 * applies each module's own registration — double-checking the registration's
 * declared panels against both the manifest and the actual panel, so a page can
 * never be registered into an unauthorized panel.
 */
final class ModulePanelPlugin implements Plugin
{
    public const ID = 'lba.modules';

    public function __construct(private readonly RegistersModules $registry) {}

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        foreach ($this->registrationsFor($panel) as $registration) {
            $registration->register($panel);
        }
    }

    public function boot(Panel $panel): void
    {
        foreach ($this->registrationsFor($panel) as $registration) {
            $registration->boot($panel);
        }
    }

    /**
     * @return list<FilamentRegistration>
     */
    private function registrationsFor(Panel $panel): array
    {
        $registrations = [];

        foreach ($this->registry->all() as $manifest) {
            if (! $manifest->allowsPanel($panel->getId())) {
                continue;
            }

            $registration = $this->registrationFor($manifest);

            if ($registration === null) {
                continue;
            }

            if (! in_array($panel->getId(), $registration->panels(), true)) {
                continue;
            }

            $registrations[] = $registration;
        }

        return $registrations;
    }

    private function registrationFor(ModuleManifest $manifest): ?FilamentRegistration
    {
        $class = $manifest->filamentRegistration;

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $registration = app($class);

        return $registration instanceof FilamentRegistration ? $registration : null;
    }
}
