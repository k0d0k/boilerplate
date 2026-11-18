<?php

namespace App\Platform\FeatureGate\Providers;

use App\Platform\FeatureGate\Contracts\GrantsModuleEntitlements;
use App\Platform\FeatureGate\EntitlementGrant;
use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;

/**
 * Central-table entitlement provider (Phase 1B-4).
 *
 * Reads the explicit `module_entitlements` grant for a module at a scope.
 * Fails closed: no row, a row that is not `granted`, or a revoked / expired
 * grant all resolve to `null` (not entitled).
 *
 * A grant is looked up by its exact scope key, so an entitlement issued for
 * Tenant A can never satisfy a request evaluated for Tenant B.
 */
final class DatabaseModuleEntitlements implements GrantsModuleEntitlements
{
    public function entitlementFor(string $moduleKey, ModuleScopeKey $scope): ?EntitlementGrant
    {
        $entitlement = ModuleEntitlement::query()
            ->where('module_key', $moduleKey)
            ->where('scope', $scope->scope)
            ->where('scope_key', $scope->scopeKey)
            ->first();

        if ($entitlement === null) {
            return null;
        }

        return new EntitlementGrant(
            granted: $entitlement->isGranted(),
            subscriptionRequired: (bool) $entitlement->subscription_required,
            subscriptionReference: $entitlement->subscription_reference,
            expiresAt: $entitlement->expires_at,
        );
    }
}
