<?php

namespace App\Platform\FeatureGate\Contracts;

use App\Platform\FeatureGate\Exceptions\ModuleAccessDeniedException;
use App\Platform\FeatureGate\FeatureGateResult;
use App\Platform\FeatureGate\ModuleAccessRequest;

/**
 * Server-side module access decision point (Phase 1B-4). DENY BY DEFAULT.
 *
 * A decision is positive only when the module is, at one verified scope,
 * `installed AND enabled AND healthy AND entitled AND subscribed AND authorized
 * AND allowed on the requested panel`. Everything else — unknown module,
 * incompatible platform, unknown permission, missing membership, missing
 * entitlement, missing subscription, disabled or unhealthy module, unauthorized
 * panel — resolves to a denial.
 *
 * The same contract serves web routes, Livewire actions, API endpoints and
 * queued jobs, so module authorization can never be bypassed by choosing a
 * different surface.
 */
interface EvaluatesModuleAccess
{
    public function evaluate(ModuleAccessRequest $request): FeatureGateResult;

    public function allows(ModuleAccessRequest $request): bool;

    /**
     * Throw {@see ModuleAccessDeniedException}
     * when access is denied.
     */
    public function authorize(ModuleAccessRequest $request): FeatureGateResult;
}
