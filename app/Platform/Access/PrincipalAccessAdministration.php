<?php

namespace App\Platform\Access;

use App\Models\PrincipalRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Support\Collection;

/**
 * WS4.5 — Platform (principal) RBAC administration.
 *
 * The write path for PLATFORM governance roles. It reuses the existing
 * `roles` / `principal_role_assignments` tables and the same
 * {@see RoleAssignmentGuard} the model layer enforces, so a principal role can
 * never be granted to a tenant or machine identity. Every grant / revoke is
 * recorded in the central, redacted audit trail ({@see ModuleAuditLog}) so it is
 * visible on the WS4.4 audit surface.
 *
 * Only platform-assignable roles are accepted here; an unknown or non-platform
 * role key is refused and audited as denied.
 */
final class PrincipalAccessAdministration
{
    /**
     * Grant a platform governance role to an identity.
     *
     * Idempotent: an identity that already holds the role actively is left
     * untouched. A previously revoked or pending assignment is reactivated
     * rather than duplicated.
     */
    public function assignRole(User $target, string $roleKey, ?User $actor = null): bool
    {
        $role = $this->assignableRole($roleKey);

        if ($role === null) {
            $this->audit($target, 'grant_role', ModuleAuditLog::RESULT_DENIED, 'role_not_assignable', $actor, $roleKey);

            return false;
        }

        $assignment = PrincipalRoleAssignment::query()
            ->where('user_id', $target->getKey())
            ->where('role_id', $role->getKey())
            ->first();

        if ($assignment !== null && $assignment->isActive()) {
            return true;
        }

        if ($assignment !== null) {
            $assignment->forceFill([
                'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
                'revoked_at' => null,
            ])->save();
        } else {
            PrincipalRoleAssignment::query()->create([
                'user_id' => $target->getKey(),
                'role_id' => $role->getKey(),
                'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
            ]);
        }

        $this->audit($target, 'grant_role', ModuleAuditLog::RESULT_ALLOWED, null, $actor, $roleKey);

        return true;
    }

    /**
     * Revoke a platform governance role from an identity. The audit row is only
     * written when an active or pending assignment actually changed.
     */
    public function revokeRole(User $target, string $roleKey, ?User $actor = null): bool
    {
        $role = $this->assignableRole($roleKey);

        if ($role === null) {
            $this->audit($target, 'revoke_role', ModuleAuditLog::RESULT_DENIED, 'role_not_assignable', $actor, $roleKey);

            return false;
        }

        $updated = PrincipalRoleAssignment::query()
            ->where('user_id', $target->getKey())
            ->where('role_id', $role->getKey())
            ->whereIn('status', [PrincipalRoleAssignment::STATUS_ACTIVE, PrincipalRoleAssignment::STATUS_PENDING])
            ->update([
                'status' => PrincipalRoleAssignment::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

        if ($updated === 0) {
            return false;
        }

        $this->audit($target, 'revoke_role', ModuleAuditLog::RESULT_ALLOWED, null, $actor, $roleKey);

        return true;
    }

    /**
     * Identities holding at least one principal role assignment.
     *
     * @return Collection<int, User>
     */
    public function identities(): Collection
    {
        return User::query()
            ->whereHas('principalRoleAssignments')
            ->with(['principalRoleAssignments.role'])
            ->orderBy('name')
            ->get();
    }

    /**
     * The role key of every active principal assignment for an identity.
     *
     * @return list<string>
     */
    public function activeRoleKeys(User $user): array
    {
        return $user->principalRoleAssignments
            ->filter(fn (PrincipalRoleAssignment $assignment) => $assignment->isActive())
            ->map(fn (PrincipalRoleAssignment $assignment) => $assignment->role?->key)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function assignableRole(string $roleKey): ?Role
    {
        if (! RbacCatalog::isPrincipalAssignableRole($roleKey)) {
            return null;
        }

        return Role::query()->where('key', $roleKey)->first();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(
        User $target,
        string $action,
        string $result,
        ?string $reason,
        ?User $actor,
        ?string $roleKey = null,
    ): void {
        ModuleAuditLog::query()->create([
            'module_key' => 'platform.rbac',
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => (string) $target->getKey(),
            'actor_id' => $actor?->getKey(),
            'metadata' => $roleKey === null ? null : ['role' => $roleKey],
        ]);
    }
}
