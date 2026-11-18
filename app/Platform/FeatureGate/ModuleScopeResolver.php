<?php

namespace App\Platform\FeatureGate;

use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\VerifiesBrandScope;
use App\Platform\Branches\Contracts\VerifiesBranchScope;
use App\Platform\ModuleRegistry\ModuleManifest;
use App\Platform\ModuleRegistry\ModuleScope;

/**
 * Resolves which scopes a module may be evaluated at for a request (Phase 1B-4R).
 *
 * A candidate is only produced when the module declares the scope AND the
 * platform can *prove* the scope. Client-supplied ids are never proof:
 *
 *  - Tenant: only the verified tenant id carried by the request's
 *    {@see AccessContext} — which the gate independently
 *    re-verifies against the identity's active membership.
 *  - Brand: only after {@see VerifiesBrandScope} confirms an explicit
 *    user -> tenant -> brand relationship. A nonempty brand id alone is never
 *    accepted.
 *  - Branch: only after {@see VerifiesBranchScope} confirms an
 *    explicit user -> tenant -> branch relationship (Phase 2B). The branch id
 *    must be the verified context branch and be an active branch of the same
 *    tenant, so a branch entitlement can never authorize another branch or
 *    tenant; an unverified branch id grants nothing.
 *
 * Candidates are ordered most-specific first (branch, tenant, brand, platform)
 * so a narrowly-scoped grant is preferred over a broader one.
 */
final class ModuleScopeResolver
{
    public function __construct(
        private readonly VerifiesBrandScope $brands,
        private readonly VerifiesBranchScope $branches,
    ) {}

    /**
     * @return list<ModuleScopeKey>
     */
    public function candidates(ModuleManifest $manifest, ModuleAccessRequest $request): array
    {
        $candidates = [];

        $tenantId = $request->context?->tenantId;
        $branchId = $request->context?->branchId;

        if (
            $manifest->supportsScope(ModuleScope::BRANCH)
            && $this->isSet($tenantId)
            && $this->isSet($branchId)
            && $this->branches->isBranchPermitted($request->user, (string) $tenantId, (string) $branchId)
        ) {
            $candidates[] = new ModuleScopeKey(ModuleScope::BRANCH, (string) $branchId);
        }

        if ($manifest->supportsScope(ModuleScope::TENANT) && $this->isSet($tenantId)) {
            $candidates[] = new ModuleScopeKey(ModuleScope::TENANT, (string) $tenantId);
        }

        if (
            $manifest->supportsScope(ModuleScope::BRAND)
            && $this->isSet($tenantId)
            && $this->isSet($request->brandId)
            && $this->brands->isBrandPermitted($request->user, (string) $tenantId, (string) $request->brandId)
        ) {
            $candidates[] = new ModuleScopeKey(ModuleScope::BRAND, (string) $request->brandId);
        }

        if ($manifest->supportsScope(ModuleScope::PLATFORM)) {
            $candidates[] = ModuleScopeKey::platform();
        }

        return $candidates;
    }

    private function isSet(?string $value): bool
    {
        return $value !== null && $value !== '';
    }
}
