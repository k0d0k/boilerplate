<?php

namespace App\Platform\Access;

use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ValidatesTenantScope;
use Illuminate\Support\Str;

/**
 * Tenant scope validation (Phase 1B-2A).
 *
 * Rejects malformed ids and any tenant the identity does not hold an eligible
 * membership for, so a future context switcher can never be tricked into acting
 * on an arbitrary or foreign tenant id.
 */
final class TenantScopeValidator implements ValidatesTenantScope
{
    public function __construct(private readonly AuthorizesTenantMembership $memberships) {}

    public function isValidFor(User $user, ?string $tenantId): bool
    {
        if ($tenantId === null || $tenantId === '' || ! Str::isUlid($tenantId)) {
            return false;
        }

        return $this->memberships->isMemberOf($user, $tenantId);
    }
}
