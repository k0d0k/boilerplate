<?php

namespace App\Platform\Access;

use App\Models\PrincipalRoleAssignment;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Exceptions\StaffInvitationException;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WS4 P0 — secure one-time staff invitation / account activation.
 *
 * A Principal never hands a staff member a usable credential. Inviting a staff
 * identity issues a single-use, expiring proof whose SHA-256 HASH alone is
 * persisted on the membership; the raw proof exists exactly once, in the
 * response to the inviting Principal, who shares it out of band. Every
 * issue/redemption is appended to the central, redacted audit trail.
 *
 * Redemption branches on the invited identity, and neither branch can ever
 * take over an established account:
 *
 *  - NEW account (created by this invitation, `users.must_set_password`): the
 *    invitee sets their OWN password (one-time setup) and the membership is
 *    activated. No password is generated, logged or exposed.
 *  - EXISTING account: the invitee must be AUTHENTICATED as that identity to
 *    accept the membership. The invitation token NEVER resets the password, so
 *    an established credential — including a Principal account — can never be
 *    taken over with a leaked or forged link.
 *
 * Redemption is atomic and single-use: the membership is flipped from `invited`
 * to `active` with a conditional update whose affected-row count is 1 for
 * exactly one caller, so concurrent redemptions of the same proof cannot both
 * succeed. An invitation is also never activated against a platform-governance
 * (Principal) identity.
 */
final class StaffInvitation
{
    /** How long a staff invitation proof stays valid. */
    public const INVITATION_TTL_HOURS = 72;

    public const ACTION_ISSUED = 'staff_invite_issued';

    public const ACTION_REDEEMED = 'staff_invite_redeemed';

    /**
     * Issue a fresh single-use, expiring proof for an invited membership and
     * return the raw proof. Only the hash is stored, so the raw value is shown
     * exactly once — to the authorized Principal who issued it.
     */
    public function issue(TenantMembership $membership, ?User $actor = null): string
    {
        $raw = Str::random(64);

        $membership->forceFill([
            'invite_token_hash' => self::hash($raw),
            'invite_expires_at' => now()->addHours(self::INVITATION_TTL_HOURS),
            'invite_issued_by' => $actor?->getKey(),
        ])->save();

        $this->audit($membership, self::ACTION_ISSUED, ModuleAuditLog::RESULT_ALLOWED, $actor);

        return $raw;
    }

    /**
     * Whether redemption for this membership is a one-time password SETUP (a
     * brand-new account) rather than an authenticated acceptance (an existing
     * account).
     */
    public function requiresPasswordSetup(TenantMembership $membership): bool
    {
        return (bool) $membership->user?->must_set_password;
    }

    /**
     * Redeem a proof.
     *
     *  - New account: `$password` is the invitee's chosen password; it is set
     *    once and the flag cleared.
     *  - Existing account: `$password` is ignored and `$actor` must be the
     *    authenticated invited identity; the password is never touched.
     *
     * An unknown, expired, already-redeemed, no-longer-invited or
     * platform-governance membership is refused (fails closed) with a single,
     * uniform error.
     */
    public function redeem(string $raw, ?string $password = null, ?User $actor = null): User
    {
        $raw = trim($raw);

        return DB::transaction(function () use ($raw, $password, $actor): User {
            $membership = $this->resolveInvited($raw);

            if (! $membership instanceof TenantMembership) {
                throw StaffInvitationException::invalid();
            }

            $user = $membership->user;

            if (! $user instanceof User) {
                throw StaffInvitationException::invalid();
            }

            // A platform-governance identity is never enrolled through a staff
            // invitation — its credential is out of scope for this flow.
            if ($this->isPrincipalIdentity($user)) {
                throw StaffInvitationException::invalid();
            }

            if ($this->requiresPasswordSetup($membership)) {
                if ($password === null || $password === '') {
                    throw StaffInvitationException::invalid();
                }
            } elseif (! $actor instanceof User
                || (string) $actor->getKey() !== (string) $user->getKey()) {
                // Existing account: prove ownership by being authenticated as
                // the invited identity. The password is never reset here.
                throw StaffInvitationException::authenticationRequired();
            }

            // Atomic single-use claim: the row flips to `active` only while it
            // is still `invited` AND still carries the presented hash. A second,
            // concurrent redemption updates zero rows and is refused.
            $claimed = TenantMembership::query()
                ->whereKey($membership->getKey())
                ->where('status', TenantMembership::STATUS_INVITED)
                ->where('invite_token_hash', $membership->invite_token_hash)
                ->update([
                    'status' => TenantMembership::STATUS_ACTIVE,
                    'activated_at' => now(),
                    'revoked_at' => null,
                    'invite_token_hash' => null,
                    'invite_expires_at' => null,
                ]);

            if ($claimed !== 1) {
                throw StaffInvitationException::invalid();
            }

            if ($user->must_set_password) {
                // The `password` cast hashes the value; the account becomes
                // usable only now, with the credential the invitee chose.
                $user->forceFill([
                    'password' => $password,
                    'is_active' => true,
                    'must_set_password' => false,
                ])->save();
            }

            $this->audit($membership, self::ACTION_REDEEMED, ModuleAuditLog::RESULT_ALLOWED, $actor ?? $user);

            return $user->refresh();
        });
    }

    /**
     * The membership a raw proof currently resolves to, if any. Used to render
     * the redemption screen without weakening the redemption check itself.
     */
    public function findRedeemable(string $raw): ?TenantMembership
    {
        return $this->resolveInvited($raw);
    }

    /**
     * The invited, unexpired membership matching the hashed proof, or null.
     */
    private function resolveInvited(string $raw): ?TenantMembership
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        return TenantMembership::query()
            ->where('invite_token_hash', self::hash($raw))
            ->whereNotNull('invite_expires_at')
            ->where('invite_expires_at', '>=', now())
            ->where('status', TenantMembership::STATUS_INVITED)
            ->first();
    }

    /**
     * A platform-governance identity: a Principal legacy role or an active
     * principal role assignment. Never reset or enrolled by an invitation.
     */
    public function isPrincipalIdentity(User $user): bool
    {
        if (in_array((string) $user->role, RoleKey::PRINCIPAL_ROLES, true)) {
            return true;
        }

        return $user->principalRoleAssignments()
            ->where('status', PrincipalRoleAssignment::STATUS_ACTIVE)
            ->exists();
    }

    private function audit(
        TenantMembership $membership,
        string $action,
        string $result,
        ?User $actor,
    ): void {
        ModuleAuditLog::query()->create([
            'module_key' => 'platform.rbac',
            'action' => $action,
            'result' => $result,
            'reason' => null,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => (string) $membership->tenant_id,
            'actor_id' => $actor?->getKey(),
            'metadata' => ['membership' => (string) $membership->getKey()],
        ]);
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
