<?php

namespace App\Platform\Access;

/**
 * Phase 1B-2A access context.
 *
 * Represents the *verified* scope a request is being authorized against. The
 * tenant id is supplied by the server (from a resolved membership or a future
 * context switcher), never trusted blindly from user input. A context with no
 * tenant id means "no tenant selected yet", which authorization treats as
 * fail-closed when the identity has more than one eligible membership.
 *
 * `branchId` is carried for forward compatibility with future branch-aware
 * policies (branches live in the tenant database in a later phase); it is an
 * opaque reference and is not used by central authorization yet.
 */
final class AccessContext
{
    public function __construct(
        public readonly ?string $tenantId = null,
        public readonly ?string $branchId = null,
    ) {}

    public static function forTenant(string $tenantId, ?string $branchId = null): self
    {
        return new self($tenantId, $branchId);
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null && $this->tenantId !== '';
    }
}
