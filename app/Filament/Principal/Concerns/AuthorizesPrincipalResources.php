<?php

namespace App\Filament\Principal\Concerns;

use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;

/**
 * Phase 7D — Principal (platform governance) resource authorization.
 *
 * Platform resources are reachable only when the acting identity is an active
 * principal whose role grants the required platform-scoped permission. The
 * decision is delegated to {@see AuthorizesPrincipal}, which resolves roles from
 * the central authorization tables — never from the client, the URL or UI
 * visibility. A non-platform permission is always denied by that authorizer, so
 * a principal role can never leak tenant/clinical authority through this path.
 */
trait AuthorizesPrincipalResources
{
    /**
     * The acting identity, or null when it is absent or suspended.
     */
    protected static function principalUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active ? $user : null;
    }

    /**
     * True only when the acting identity holds the platform-scoped permission.
     */
    protected static function principalCan(string $permission): bool
    {
        $user = static::principalUser();

        if ($user === null) {
            return false;
        }

        return app(AuthorizesPrincipal::class)->can($user, $permission);
    }
}
