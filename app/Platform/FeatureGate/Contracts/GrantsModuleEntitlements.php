<?php

namespace App\Platform\FeatureGate\Contracts;

use App\Platform\FeatureGate\EntitlementGrant;
use App\Platform\FeatureGate\ModuleScopeKey;

/**
 * Resolves the entitlement of one module at one scope (Phase 1B-4).
 *
 * An implementation must FAIL CLOSED: when it cannot verify a grant it returns
 * `null` (no entitlement), never a fabricated active grant. The Phase 2
 * database-per-tenant isolation will swap in a tenant-database-backed resolver
 * behind this same contract.
 */
interface GrantsModuleEntitlements
{
    public function entitlementFor(string $moduleKey, ModuleScopeKey $scope): ?EntitlementGrant;
}
