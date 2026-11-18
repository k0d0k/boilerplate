<?php

namespace App\Platform\FeatureGate;

/**
 * The resolved installed / enabled / healthy state of one module at one scope
 * (Phase 1B-4R).
 *
 * Derived from explicit trusted activation: the module's own declaration plus
 * the platform catalog row plus the per-scope installation row. All three legs
 * must hold — an explicit "disabled" or "unhealthy" anywhere denies access, and
 * a *missing* catalog or installation record fails closed rather than implying
 * a live module.
 */
final class ModuleState
{
    public function __construct(
        public readonly bool $installed,
        public readonly bool $enabled,
        public readonly bool $healthy,
    ) {}
}
