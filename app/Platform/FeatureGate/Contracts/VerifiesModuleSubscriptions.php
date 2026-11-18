<?php

namespace App\Platform\FeatureGate\Contracts;

use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\FeatureGate\Providers\DatabaseModuleSubscriptions;

/**
 * Verifies that a module's subscription is active at one scope (Phase 1B-4).
 *
 * WS4 binds this to {@see DatabaseModuleSubscriptions}, which honours a live
 * tenant licence from verified central data. Every implementation MUST fail
 * closed: a module that requires a subscription is denied unless a real, active
 * subscription licenses it.
 */
interface VerifiesModuleSubscriptions
{
    public function isActive(string $moduleKey, ModuleScopeKey $scope, ?string $reference): bool;
}
