<?php

namespace App\Platform\FeatureGate;

use App\Platform\FeatureGate\Contracts\ResolvesModuleState;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\ModuleManifest;

/**
 * Validates a module's manifest `requiredDependencies` (Phase 1B-4R).
 *
 * A dependency is satisfied only when it is present, enabled, healthy and
 * compatible with the running platform at the scope being evaluated. Anything
 * else — a missing module, a disabled or unhealthy one, an incompatible one, or
 * an unsatisfied platform capability — denies access.
 *
 * Platform dependencies (`platform.*`) are kept distinct from business modules:
 * they name a capability the platform itself provides (configured in
 * `lba.platform.capabilities`) and are never looked up as modules. No PHP is
 * executed and no package is installed to satisfy a dependency; the check is
 * pure metadata plus the same central-table state the gate already uses.
 *
 * Dependency graphs are walked with an explicit *visiting* set, so a circular
 * dependency is detected and denied instead of being processed forever.
 */
final class ModuleDependencyValidator
{
    /** @var array<string, true> */
    private array $resolved = [];

    /** @var array<string, true> */
    private array $visiting = [];

    public function __construct(
        private readonly RegistersModules $registry,
        private readonly ResolvesModuleState $state,
    ) {}

    /**
     * The denial reason for the first unsatisfied dependency, or null when the
     * module's whole dependency graph is satisfied at the given scope.
     */
    public function failure(ModuleManifest $manifest, ModuleScopeKey $scope): ?string
    {
        $this->resolved = [];
        $this->visiting = [];

        foreach ($manifest->requiredDependencies as $dependency) {
            $failure = $this->require($dependency, $scope);

            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    private function require(string $dependency, ModuleScopeKey $scope): ?string
    {
        if (ModuleManifest::isPlatformDependency($dependency)) {
            return $this->platformProvides($dependency)
                ? null
                : FeatureGateReason::UNSATISFIED_PLATFORM_DEPENDENCY;
        }

        if (isset($this->resolved[$dependency])) {
            return null;
        }

        if (isset($this->visiting[$dependency])) {
            return FeatureGateReason::CIRCULAR_DEPENDENCY;
        }

        $manifest = $this->registry->find($dependency);

        if ($manifest === null) {
            return FeatureGateReason::MISSING_DEPENDENCY;
        }

        if (! $manifest->isCompatibleWithPlatform($this->registry->platformVersion())) {
            return FeatureGateReason::INCOMPATIBLE_DEPENDENCY;
        }

        $state = $this->state->stateFor($manifest, $scope);

        if (! $state->installed) {
            return FeatureGateReason::MISSING_DEPENDENCY;
        }

        if (! $state->enabled) {
            return FeatureGateReason::DISABLED_DEPENDENCY;
        }

        if (! $state->healthy) {
            return FeatureGateReason::UNHEALTHY_DEPENDENCY;
        }

        $this->visiting[$dependency] = true;

        foreach ($manifest->requiredDependencies as $nested) {
            $failure = $this->require($nested, $scope);

            if ($failure !== null) {
                unset($this->visiting[$dependency]);

                return $failure;
            }
        }

        unset($this->visiting[$dependency]);
        $this->resolved[$dependency] = true;

        return null;
    }

    private function platformProvides(string $capability): bool
    {
        $capabilities = config('lba.platform.capabilities', []);

        return is_array($capabilities) && in_array($capability, $capabilities, true);
    }
}
