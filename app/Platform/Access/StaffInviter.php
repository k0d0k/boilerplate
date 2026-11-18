<?php

namespace App\Platform\Access;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * WS4 P0 / Phase 3 — single write path for inviting a staff identity.
 *
 * Extracted from the Principal Memberships screen so BOTH the manual invite
 * action and the one-click "Setup Clinic" flow create invitations identically:
 *
 *  - an existing central identity is REUSED (its password is never touched);
 *  - a brand-new identity is created WITHOUT a usable or exposed credential and
 *    flagged `must_set_password`, so the invitee sets their OWN password when
 *    they redeem the single-use link;
 *  - the membership is created `invited` (grants nothing until activated);
 *  - a platform-governance (Principal) identity is refused outright;
 *  - an already-active membership is reported, never silently re-invited.
 *
 * The raw activation proof is issued through {@see StaffInvitation} and returned
 * exactly once in the outcome.
 */
final class StaffInviter
{
    public function __construct(private readonly StaffInvitation $invitations) {}

    /**
     * @param  list<string>  $roleKeys  clinic-routable role keys for the invitee
     */
    public function invite(
        Tenant $tenant,
        string $email,
        string $name,
        array $roleKeys,
        ?User $actor = null,
    ): StaffInvitationOutcome {
        $email = trim($email);
        $name = trim($name);
        $roleKeys = array_values(array_filter(array_map('strval', $roleKeys)));

        if ($email === '' || $roleKeys === []) {
            return StaffInvitationOutcome::invalid();
        }

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User && $this->invitations->isPrincipalIdentity($user)) {
            return StaffInvitationOutcome::principalForbidden();
        }

        $newAccount = false;

        if (! $user instanceof User) {
            // The account is created WITHOUT a usable, or exposed, credential
            // and flagged `must_set_password`. The random value is never
            // surfaced, logged or transmitted — it only makes the account
            // unauthenticatable until activation.
            $newAccount = true;
            $user = User::query()->create([
                'name' => $name !== '' ? $name : $email,
                'email' => $email,
                'password' => Str::password(64),
                'must_set_password' => true,
                'role' => $roleKeys[0],
                'is_active' => true,
            ]);
        }

        $membership = TenantMembership::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'tenant_id' => $tenant->getKey()],
            ['status' => TenantMembership::STATUS_INVITED],
        );

        if ($membership->status === TenantMembership::STATUS_ACTIVE) {
            return StaffInvitationOutcome::alreadyMember($user);
        }

        $membership->update([
            'status' => TenantMembership::STATUS_INVITED,
            'revoked_at' => null,
        ]);

        $ids = Role::query()->whereIn('key', $roleKeys)->pluck('id')->all();
        $membership->roles()->sync($ids);

        $proof = $this->invitations->issue($membership, $actor);
        $activationUrl = route('staff.invitation.show', ['token' => $proof]);

        return StaffInvitationOutcome::invited($membership, $activationUrl, $newAccount);
    }
}
