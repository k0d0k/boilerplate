<?php

namespace App\Platform\FeatureGate\Providers;

use App\Platform\FeatureGate\Contracts\VerifiesModuleSubscriptions;
use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;

/**
 * WS4 — central-table subscription verifier.
 *
 * Replaces the fail-closed placeholder now that tenant licensing exists. Given
 * the scope an entitlement was granted for and the opaque `reference` recorded
 * on it, it answers whether a live subscription actually licenses the module.
 *
 * Fails closed on every ambiguity:
 *  - no reference, or a scope that is not a tenant, denies;
 *  - an unknown reference, or one that belongs to a DIFFERENT tenant, denies;
 *  - a subscription that is not active/trialing or is outside its period denies;
 *  - a plan that does not license the module key denies.
 *
 * The decision is read only from verified central data, so a module that
 * requires a subscription can never be allowed by declaring one client-side.
 */
final class DatabaseModuleSubscriptions implements VerifiesModuleSubscriptions
{
    public function isActive(string $moduleKey, ModuleScopeKey $scope, ?string $reference): bool
    {
        if ($reference === null || $reference === '') {
            return false;
        }

        if ($scope->scope !== ModuleScope::TENANT || $scope->scopeKey === '') {
            return false;
        }

        $subscription = TenantSubscription::query()
            ->where('reference', $reference)
            ->where('tenant_id', $scope->scopeKey)
            ->first();

        if (! $subscription instanceof TenantSubscription || ! $subscription->isActive()) {
            return false;
        }

        $plan = $subscription->plan;

        if (! $plan instanceof SubscriptionPlan) {
            return false;
        }

        return $plan->licensesModule($moduleKey);
    }
}
