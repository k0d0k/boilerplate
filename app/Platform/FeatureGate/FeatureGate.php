<?php

namespace App\Platform\FeatureGate;

use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Contracts\EvaluatesModuleAccess;
use App\Platform\FeatureGate\Contracts\GrantsModuleEntitlements;
use App\Platform\FeatureGate\Contracts\ResolvesModuleState;
use App\Platform\FeatureGate\Contracts\VerifiesModuleSubscriptions;
use App\Platform\FeatureGate\Exceptions\ModuleAccessDeniedException;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\ModuleManifest;

/**
 * Server-side FeatureGate (Phase 1B-4). DENY BY DEFAULT.
 *
 * Effective access, evaluated with a verified tenant/brand/branch scope:
 *
 *     installed && healthy && entitled && subscribed && authorized && allowed_panel
 *
 * Security guarantees:
 *  - no module declaration, membership or entitlement => no access;
 *  - a disabled or unhealthy module can never execute a protected action;
 *  - an entitlement issued for one scope key never authorizes another;
 *  - unimplemented subscription/entitlement providers fail closed;
 *  - a missing, disabled, unhealthy or incompatible dependency denies access,
 *    and a circular dependency graph is detected rather than processed;
 *  - brand-scoped grants require an explicitly verified user -> tenant -> brand
 *    relationship, and branch-scoped grants require an explicitly verified
 *    user -> tenant -> branch relationship (Phase 2B);
 *  - the same decision is produced for routes, Livewire, APIs and jobs, so UI
 *    visibility is never the authorization boundary.
 *
 * The gate is deliberately scope-first: it never invents a scope. When no
 * verified scope is available for a module that needs one, it denies.
 */
final class FeatureGate implements EvaluatesModuleAccess
{
    public function __construct(
        private readonly RegistersModules $registry,
        private readonly ResolvesModuleState $state,
        private readonly GrantsModuleEntitlements $entitlements,
        private readonly VerifiesModuleSubscriptions $subscriptions,
        private readonly ChecksPermissions $permissions,
        private readonly AuthorizesTenantMembership $memberships,
        private readonly ModuleScopeResolver $scopes,
        private readonly ModuleDependencyValidator $dependencies,
    ) {}

    public function evaluate(ModuleAccessRequest $request): FeatureGateResult
    {
        $manifest = $this->registry->find($request->moduleKey);

        if ($manifest === null) {
            return FeatureGateResult::deny(FeatureGateReason::UNKNOWN_MODULE, $request->moduleKey, $request->panelId);
        }

        if (! $manifest->allowsPanel($request->panelId)) {
            return FeatureGateResult::deny(FeatureGateReason::PANEL_NOT_ALLOWED, $manifest->key, $request->panelId);
        }

        if (! $manifest->isCompatibleWithPlatform($this->registry->platformVersion())) {
            return FeatureGateResult::deny(FeatureGateReason::INCOMPATIBLE_PLATFORM, $manifest->key, $request->panelId);
        }

        $authorization = $this->authorizationFailure($manifest, $request);

        if ($authorization !== null) {
            return FeatureGateResult::deny($authorization, $manifest->key, $request->panelId);
        }

        $candidates = $this->scopes->candidates($manifest, $request);

        if ($candidates === []) {
            return FeatureGateResult::deny(FeatureGateReason::NO_VERIFIED_SCOPE, $manifest->key, $request->panelId);
        }

        $failure = null;

        foreach ($candidates as $scope) {
            $result = $this->evaluateScope($manifest, $request, $scope);

            if ($result->allowed) {
                return $result;
            }

            $failure ??= $result;
        }

        return $failure;
    }

    public function allows(ModuleAccessRequest $request): bool
    {
        return $this->evaluate($request)->allowed;
    }

    public function authorize(ModuleAccessRequest $request): FeatureGateResult
    {
        $result = $this->evaluate($request);

        if ($result->denied()) {
            throw ModuleAccessDeniedException::forResult($result);
        }

        return $result;
    }

    /**
     * The scope-independent authorization leg, or the denial reason.
     *
     * Runs before any scope work so an unauthorized identity learns nothing
     * about a module's installed/enabled/entitled state.
     */
    private function authorizationFailure(ModuleManifest $manifest, ModuleAccessRequest $request): ?string
    {
        $user = $request->user;

        if ($user === null || ! $user->is_active) {
            return FeatureGateReason::UNAUTHORIZED;
        }

        if (! PanelAccessPolicy::roleCanAccessPanel($user->role, $request->panelId)) {
            return FeatureGateReason::UNAUTHORIZED;
        }

        foreach ($manifest->requiredPermissions as $permission) {
            if (! in_array($permission, PermissionKey::ALL, true)) {
                return FeatureGateReason::UNKNOWN_PERMISSION;
            }

            if (! $this->permissions->allows($user, $permission, $request->context)) {
                return FeatureGateReason::UNAUTHORIZED;
            }
        }

        if ($request->context?->hasTenant()) {
            if (! $this->memberships->isMemberOf($user, $request->context->tenantId)) {
                return FeatureGateReason::UNAUTHORIZED;
            }
        }

        return null;
    }

    private function evaluateScope(
        ModuleManifest $manifest,
        ModuleAccessRequest $request,
        ModuleScopeKey $scope,
    ): FeatureGateResult {
        $state = $this->state->stateFor($manifest, $scope);

        if (! $state->installed) {
            return FeatureGateResult::deny(FeatureGateReason::NOT_INSTALLED, $manifest->key, $request->panelId, $scope);
        }

        if (! $state->enabled) {
            return FeatureGateResult::deny(FeatureGateReason::DISABLED, $manifest->key, $request->panelId, $scope);
        }

        if (! $state->healthy) {
            return FeatureGateResult::deny(FeatureGateReason::UNHEALTHY, $manifest->key, $request->panelId, $scope);
        }

        $dependencyFailure = $this->dependencies->failure($manifest, $scope);

        if ($dependencyFailure !== null) {
            return FeatureGateResult::deny($dependencyFailure, $manifest->key, $request->panelId, $scope);
        }

        $grant = $this->entitlements->entitlementFor($manifest->key, $scope);

        if ($grant === null || ! $grant->granted) {
            return FeatureGateResult::deny(FeatureGateReason::NOT_ENTITLED, $manifest->key, $request->panelId, $scope);
        }

        $requiresSubscription = $manifest->requiresSubscription || $grant->subscriptionRequired;

        if ($requiresSubscription && ! $this->subscriptions->isActive($manifest->key, $scope, $grant->subscriptionReference)) {
            return FeatureGateResult::deny(FeatureGateReason::NO_SUBSCRIPTION, $manifest->key, $request->panelId, $scope);
        }

        return FeatureGateResult::allow($manifest->key, $request->panelId, $scope);
    }
}
