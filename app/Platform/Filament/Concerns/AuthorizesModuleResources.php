<?php

namespace App\Platform\Filament\Concerns;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\FeatureGate\ModuleGate;

/**
 * Shared Clinic module-resource authorization (Phase 3).
 *
 * A business module resource is reachable only when BOTH hold:
 *  - the server-side FeatureGate allows the module in the Clinic panel for the
 *    *verified* tenant/branch context (module registration/activation), and
 *  - the acting identity's tenant role grants the required canonical permission.
 *
 * Every value is re-derived from trusted services (never from client input), so
 * UI visibility is never the authorization boundary.
 */
trait AuthorizesModuleResources
{
    /**
     * The manifest key of the module owning this resource.
     */
    abstract protected static function moduleKey(): string;

    protected static function moduleActive(): bool
    {
        return app(ModuleGate::class)->allows(static::moduleKey(), PanelAccessPolicy::PANEL_CLINIC);
    }

    protected static function allows(string $permission): bool
    {
        $user = static::currentUser();

        if ($user === null) {
            return false;
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return false;
        }

        $branchId = app(ManagesBranchContext::class)->currentBranchId($user);

        return app(ChecksPermissions::class)->allows(
            $user,
            $permission,
            AccessContext::forTenant($tenantId, $branchId),
        );
    }

    /**
     * Ids of the branches the identity may operate in for the verified tenant.
     *
     * @return list<string>
     */
    protected static function accessibleBranchIds(): array
    {
        $user = static::currentUser();

        if ($user === null) {
            return [];
        }

        return array_map(
            static fn ($option): string => $option->id,
            app(ManagesBranchContext::class)->eligibleOptions($user),
        );
    }

    /**
     * Branch id => name options the identity may operate in.
     *
     * @return array<string, string>
     */
    protected static function accessibleBranchOptions(): array
    {
        $user = static::currentUser();

        if ($user === null) {
            return [];
        }

        $options = [];

        foreach (app(ManagesBranchContext::class)->eligibleOptions($user) as $option) {
            $options[$option->id] = $option->name;
        }

        return $options;
    }

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active ? $user : null;
    }
}
