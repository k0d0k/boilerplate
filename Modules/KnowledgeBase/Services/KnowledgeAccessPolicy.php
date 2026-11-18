<?php

namespace Modules\KnowledgeBase\Services;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Support\KnowledgeScope;

/**
 * WS9 — the single, server-side decision for whether an acting identity may
 * read a knowledge document's private PDF (and, from WS9.B, its chunks).
 *
 * It is deliberately conservative:
 *  - the document must be PUBLISHED (a draft/review/archived/revoked source is
 *    never readable, so an unapproved upload can never leak);
 *  - the acting identity must have a VERIFIED tenant context;
 *  - a TENANT-scope document is readable only by its OWNING tenant — the scope
 *    key is compared to the server-derived tenant, never to client input, so
 *    cross-tenant access fails closed;
 *  - a PLATFORM-scope document (globally published reference) is readable by
 *    any entitled staff member of a tenant.
 *
 * The scope/key are always re-derived from trusted services, so the caller can
 * never choose the tenant or the scope.
 */
final class KnowledgeAccessPolicy
{
    public function __construct(
        private readonly ManagesTenantContext $tenants,
        private readonly ChecksPermissions $permissions,
    ) {}

    /**
     * Whether the acting identity may read the document's private source.
     */
    public function canRead(User $user, KnowledgeDocument $document): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $document->isPublished()) {
            return false;
        }

        $tenantId = $this->tenants->currentTenantId($user);

        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        // Tenant isolation: a tenant-scope document belongs to exactly one
        // tenant. Anything else is invisible — not merely forbidden — so ids
        // cannot be probed.
        if ($document->scope === KnowledgeScope::TENANT && (string) $document->scope_key !== (string) $tenantId) {
            return false;
        }

        $context = AccessContext::forTenant($tenantId, null);

        // A knowledge curator may read anything in reach of their tenant; a
        // clinician who may read examinations may also open the reference the
        // assistant cited.
        if ($this->permissions->allows($user, PermissionKey::KNOWLEDGE_MANAGE, $context)) {
            return true;
        }

        return $this->permissions->allows($user, PermissionKey::EXAM_READ, $context);
    }

    /**
     * Whether the acting identity may author / approve knowledge documents in
     * the given scope. Only an entitled tenant curator may act on TENANT scope;
     * PLATFORM scope is a Principal act enforced by the Principal permission.
     */
    public function canManage(User $user, string $scope): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($scope === KnowledgeScope::PLATFORM) {
            return false;
        }

        $tenantId = $this->tenants->currentTenantId($user);

        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        return $this->permissions->allows(
            $user,
            PermissionKey::KNOWLEDGE_MANAGE,
            AccessContext::forTenant($tenantId, null),
        );
    }
}
