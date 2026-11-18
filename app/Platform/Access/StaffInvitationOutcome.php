<?php

namespace App\Platform\Access;

use App\Models\TenantMembership;
use App\Models\User;

/**
 * Outcome of a staff invitation attempt (WS4 P0 / Phase 3 one-click setup).
 *
 * A value object so the CALLER (a Filament action or the clinic setup service)
 * decides how to present the result — the inviting Principal sees the raw
 * activation URL exactly once, and no other outcome shape is possible.
 */
final class StaffInvitationOutcome
{
    public const STATUS_INVITED = 'invited';

    public const STATUS_ALREADY_MEMBER = 'already_member';

    public const STATUS_PRINCIPAL_FORBIDDEN = 'principal_forbidden';

    public const STATUS_INVALID = 'invalid';

    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        public readonly ?TenantMembership $membership = null,
        public readonly ?string $activationUrl = null,
        public readonly bool $newAccount = false,
    ) {}

    public static function invited(TenantMembership $membership, string $activationUrl, bool $newAccount): self
    {
        return new self(self::STATUS_INVITED, $membership->user, $membership, $activationUrl, $newAccount);
    }

    public static function alreadyMember(?User $user = null): self
    {
        return new self(self::STATUS_ALREADY_MEMBER, $user);
    }

    public static function principalForbidden(): self
    {
        return new self(self::STATUS_PRINCIPAL_FORBIDDEN);
    }

    public static function invalid(): self
    {
        return new self(self::STATUS_INVALID);
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_INVITED;
    }
}
