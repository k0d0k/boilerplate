<?php

namespace App\Platform\Filament;

use App\Platform\Filament\Contracts\FilamentRegistration;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Base module-to-panel registration (Phase 1B-4).
 *
 * Modules extend this and declare their Filament Resources, Custom Pages,
 * Widgets, Policies and navigation; the platform applies them to the correct
 * panel. {@see register()} is final on purpose: a module cannot bypass the
 * panel guard, and every module follows the same registration path.
 */
abstract class AbstractModuleRegistration implements FilamentRegistration
{
    final public function register(Panel $panel): void
    {
        if (! in_array($panel->getId(), $this->panels(), true)) {
            throw new LogicException(sprintf(
                'Module [%s] may not register into unauthorized panel [%s].',
                $this->key(),
                $panel->getId(),
            ));
        }

        $resources = $this->resources();

        if ($resources !== []) {
            $panel->resources($resources);
        }

        $pages = $this->pages();

        if ($pages !== []) {
            $panel->pages($pages);
        }

        $widgets = $this->widgets();

        if ($widgets !== []) {
            $panel->widgets($widgets);
        }

        foreach ($this->policies() as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    public function boot(Panel $panel): void {}

    /**
     * @return list<class-string>
     */
    public function resources(): array
    {
        return [];
    }

    /**
     * @return list<class-string>
     */
    public function pages(): array
    {
        return [];
    }

    /**
     * @return list<class-string>
     */
    public function widgets(): array
    {
        return [];
    }

    /**
     * @return array<class-string, class-string> model => policy
     */
    public function policies(): array
    {
        return [];
    }

    /**
     * Optional navigation group label used by this module's pages.
     */
    public function navigationGroup(): ?string
    {
        return null;
    }
}
