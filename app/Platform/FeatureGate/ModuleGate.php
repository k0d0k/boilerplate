<?php

namespace App\Platform\FeatureGate;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\FeatureGate\Contracts\EvaluatesModuleAccess;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

/**
 * Convenience entry point for the server-side FeatureGate (Phase 1B-4).
 *
 * Resolves the *verified* identity and tenant context from the authenticated
 * session and evaluates a module against the current Filament panel — so a
 * module page, a Livewire action or a policy can enforce the gate with a single
 * call and can never accidentally pass an unverified tenant id.
 *
 * Callers that are not part of an HTTP session (APIs, queued jobs) should build
 * a {@see ModuleAccessRequest} themselves and use {@see EvaluatesModuleAccess}
 * directly.
 */
final class ModuleGate
{
    public function __construct(
        private readonly EvaluatesModuleAccess $gate,
        private readonly ManagesTenantContext $tenants,
        private readonly ManagesBranchContext $branches,
    ) {}

    public function allows(string $moduleKey, ?string $panelId = null): bool
    {
        return $this->forCurrentContext($moduleKey, $panelId)->allowed;
    }

    public function authorize(string $moduleKey, ?string $panelId = null): FeatureGateResult
    {
        return $this->gate->authorize($this->requestFor($moduleKey, $panelId));
    }

    public function forCurrentContext(string $moduleKey, ?string $panelId = null): FeatureGateResult
    {
        return $this->gate->evaluate($this->requestFor($moduleKey, $panelId));
    }

    public function requestFor(string $moduleKey, ?string $panelId = null): ModuleAccessRequest
    {
        $panelId ??= Filament::getCurrentPanel()?->getId() ?? '';

        return new ModuleAccessRequest(
            moduleKey: $moduleKey,
            panelId: $panelId,
            user: $this->currentUser(),
            context: $this->currentContext(),
        );
    }

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function currentContext(): ?AccessContext
    {
        $user = $this->currentUser();

        if ($user === null) {
            return null;
        }

        $tenantId = $this->tenants->currentTenantId($user);

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        // Only a VERIFIED active branch of the selected tenant ever enters the
        // context; with no eligible branch it stays null so branch-scoped
        // modules are denied by default.
        $branchId = $this->branches->currentBranchId($user);

        return AccessContext::forTenant($tenantId, $branchId);
    }
}
