<?php

namespace App\Platform\FeatureGate\Contracts;

use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\FeatureGate\ModuleState;
use App\Platform\ModuleRegistry\ModuleManifest;

/**
 * Resolves the effective installed / enabled / healthy state of a module at a
 * scope from explicit trusted activation: the module's own declaration plus the
 * central catalog row plus the per-scope installation row (Phase 1B-4R).
 *
 * A missing catalog or per-scope installation record fails closed — it never
 * implies installed, enabled or healthy.
 */
interface ResolvesModuleState
{
    public function stateFor(ModuleManifest $manifest, ModuleScopeKey $scope): ModuleState;
}
