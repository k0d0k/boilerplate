<?php

namespace Tests\Support;

use App\Models\PrincipalRoleAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;

/**
 * Helpers for building synthetic Phase 1B-2A identities.
 *
 * Uses only synthetic users, tenants and memberships — never real patient or
 * clinical data (DECISIONS.md PRV-01).
 */
trait BuildsAccess
{
    protected function roleId(string $roleKey): string
    {
        return Role::query()->where('key', $roleKey)->firstOrFail()->getKey();
    }

    protected function grantPrincipalRole(User $user, string $roleKey): PrincipalRoleAssignment
    {
        return PrincipalRoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $this->roleId($roleKey),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  list<string>  $roleKeys
     */
    protected function giveMembership(
        User $user,
        Tenant $tenant,
        array $roleKeys = [],
        string $status = TenantMembership::STATUS_ACTIVE,
    ): TenantMembership {
        $membership = TenantMembership::factory()
            ->forUser($user)
            ->forTenant($tenant)
            ->create(['status' => $status]);

        foreach ($roleKeys as $roleKey) {
            $membership->roles()->attach($this->roleId($roleKey));
        }

        return $membership->refresh();
    }
}
