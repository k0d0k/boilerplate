<?php

namespace App\Platform\Access;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves which panel an authenticated identity is allowed to enter.
 *
 * Phase 1B-1 uses this to drive the unified staff login: the backend — never
 * the client — decides the destination panel after credentials are verified.
 * The decision is delegated to the same server-side gate the panels already
 * enforce (`FilamentUser::canAccessPanel()`, backed by {@see PanelAccessPolicy}),
 * so the login redirect and the request-time authorization can never disagree.
 *
 * The provisional role => panel policy maps a role to exactly one panel, so at
 * most one panel matches. When the Phase 1B-2 membership model lands it only
 * has to change the policy, not this resolver.
 */
final class PanelResolver
{
    /** Panels that the unified staff login may route a verified identity to. */
    public const STAFF_PANELS = [
        PanelAccessPolicy::PANEL_PRINCIPAL,
        PanelAccessPolicy::PANEL_CLINIC,
    ];

    /**
     * The panel the identity may enter, or null when denied everywhere
     * (unknown role, absent role or suspended account).
     */
    public function panelPathFor(?Authenticatable $user): ?string
    {
        if (! $user instanceof FilamentUser) {
            return null;
        }

        foreach (PanelAccessPolicy::PANELS as $panelId) {
            if ($user->canAccessPanel(Filament::getPanel($panelId))) {
                return $panelId;
            }
        }

        return null;
    }

    /**
     * Same as {@see panelPathFor()} but restricted to the staff panels, so a
     * patient identity is never routed through the unified staff login.
     */
    public function staffPanelPathFor(?Authenticatable $user): ?string
    {
        $panelId = $this->panelPathFor($user);

        return in_array($panelId, self::STAFF_PANELS, true) ? $panelId : null;
    }
}
