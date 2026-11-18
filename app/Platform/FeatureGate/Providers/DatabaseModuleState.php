<?php

namespace App\Platform\FeatureGate\Providers;

use App\Platform\FeatureGate\Contracts\ResolvesModuleState;
use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\FeatureGate\ModuleState;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleManifest;

/**
 * Central-table module state resolver (Phase 1B-4R).
 *
 * State is derived from *explicit trusted activation*, never implied. A module
 * is usable at a scope only when:
 *
 *  - its declaration is present and agrees (the code exists and loads);
 *  - the platform `module_registry` catalog row EXISTS and is installed,
 *    enabled and healthy — the platform-wide master switch;
 *  - for any non-platform scope, the per-scope `module_installations` row
 *    EXISTS and is installed, enabled and healthy.
 *
 * A missing catalog row, or a missing per-scope installation row, resolves to
 * NOT installed / NOT enabled / NOT healthy (fail closed). Nothing is inherited
 * from a broader level, so absence can never silently imply a live module.
 *
 * Installation, health and entitlement stay separate concerns: this resolver
 * answers only the first two. Entitlement is decided independently by the
 * gate's entitlement provider.
 */
final class DatabaseModuleState implements ResolvesModuleState
{
    public function stateFor(ModuleManifest $manifest, ModuleScopeKey $scope): ModuleState
    {
        $catalog = RegisteredModule::query()
            ->where('module_key', $manifest->key)
            ->first();

        // No explicit catalog row: the module has not been activated on the
        // platform, so it is not installed, enabled or healthy.
        if ($catalog === null) {
            return new ModuleState(false, false, false);
        }

        $installed = $manifest->installed && $catalog->is_installed;
        $enabled = $manifest->isEnabled() && $catalog->isEnabled();
        $healthy = $manifest->isHealthy() && $catalog->isHealthy();

        // The platform scope is the catalog-level switch itself; every scoped
        // install additionally requires its own explicit installation record.
        if (! $scope->isPlatform()) {
            $installation = ModuleInstallation::query()
                ->where('module_key', $manifest->key)
                ->where('scope', $scope->scope)
                ->where('scope_key', $scope->scopeKey)
                ->first();

            if ($installation === null) {
                return new ModuleState(false, false, false);
            }

            $installed = $installed && $installation->is_installed;
            $enabled = $enabled && $installation->isEnabled();
            $healthy = $healthy && $installation->isHealthy();
        }

        return new ModuleState($installed, $enabled, $healthy);
    }
}
