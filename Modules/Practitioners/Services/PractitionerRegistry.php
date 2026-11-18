<?php

namespace Modules\Practitioners\Services;

use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\UserCategory;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Carbon\CarbonInterface;
use Closure;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Models\PractitionerAuditLog;
use Modules\Practitioners\Models\PractitionerBranchAuthorization;
use Modules\Practitioners\Models\PractitionerCredential;

/**
 * WS7.A — the practitioner registry chokepoint.
 *
 * Every write happens inside a VERIFIED tenant transaction and every decision
 * is re-derived on the SERVER from trusted services (never from client input):
 *
 *  - registering a practitioner requires `practitioner.profile.manage` AND that
 *    the target user is an ACTIVE clinical member of the verified tenant
 *    (consultant / lba_operator / reviewer). Being a clinical member is NOT
 *    evidence of qualification — the profile is created `pending`;
 *  - verifying or suspending a practitioner / credential requires
 *    `practitioner.credential.verify`, is audited (actor + timestamp), and is
 *    REFUSED when the actor is the practitioner themselves (separation of
 *    duties). Nothing is ever auto-verified;
 *  - suspension, expiry, archival and loss of clinical membership immediately
 *    stop the practitioner being bookable ({@see self::isEligibleForBooking()}),
 *    while historical appointments, consultations and examinations are kept.
 */
final class PractitionerRegistry
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly ManagesTenantContext $tenants,
        private readonly ChecksPermissions $permissions,
        private readonly ResolvesTenantDatabase $databases,
    ) {}

    /**
     * Register a new practitioner profile (starts `pending`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(User $actor, string $userId, array $attributes): Practitioner
    {
        $tenantId = $this->assertCanManage($actor);

        $userId = trim($userId);

        if ($userId === '') {
            throw PractitionerException::userNotEligible();
        }

        $this->assertClinicalMembership($userId, $tenantId);

        return $this->withTenant($tenantId, function () use ($actor, $userId, $attributes): Practitioner {
            if (Practitioner::query()->where('user_id', $userId)->exists()) {
                throw PractitionerException::alreadyRegistered();
            }

            $practitioner = new Practitioner;
            $practitioner->fill($attributes);
            $practitioner->forceFill([
                'user_id' => $userId,
                'status' => Practitioner::STATUS_PENDING,
                'created_by' => (string) $actor->getKey(),
            ]);

            $practitioner->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_REGISTERED, $actor, [
                'display_name' => $practitioner->display_name,
            ]);

            return $practitioner;
        });
    }

    /**
     * Update the non-sensitive professional profile fields.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Practitioner $practitioner, array $attributes): Practitioner
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner, $attributes): Practitioner {
            $practitioner->fill($attributes)->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_UPDATED, $actor, $attributes);

            return $practitioner;
        });
    }

    /**
     * Explicitly verify a pending practitioner (audited; never automatic).
     */
    public function verify(
        User $actor,
        Practitioner $practitioner,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
    ): Practitioner {
        $tenantId = $this->assertCanVerify($actor);
        $this->assertNotSelf($actor, $practitioner);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner, $expiresAt, $notes): Practitioner {
            if (! $practitioner->isPending()) {
                throw PractitionerException::notPending();
            }

            $practitioner->forceFill([
                'status' => Practitioner::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => (string) $actor->getKey(),
                'expires_at' => $expiresAt,
                'notes' => $notes ?? $practitioner->notes,
                'suspended_at' => null,
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_VERIFIED, $actor);

            return $practitioner;
        });
    }

    /**
     * Suspend a practitioner (non-destructive): blocks new bookings and
     * qualified clinical actions while preserving history.
     */
    public function suspend(User $actor, Practitioner $practitioner, ?string $note = null): Practitioner
    {
        $tenantId = $this->assertCanVerify($actor);
        $this->assertNotSelf($actor, $practitioner);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner, $note): Practitioner {
            $practitioner->forceFill([
                'status' => Practitioner::STATUS_SUSPENDED,
                'suspended_at' => now(),
                'notes' => $note ?? $practitioner->notes,
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_SUSPENDED, $actor);

            return $practitioner;
        });
    }

    /**
     * Reactivate a suspended practitioner. Returns to `verified` unless the
     * verification window has lapsed, in which case a fresh verification is
     * required (`pending`).
     */
    public function reactivate(User $actor, Practitioner $practitioner): Practitioner
    {
        $tenantId = $this->assertCanVerify($actor);
        $this->assertNotSelf($actor, $practitioner);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner): Practitioner {
            $status = $practitioner->isExpired() ? Practitioner::STATUS_PENDING : Practitioner::STATUS_VERIFIED;

            $practitioner->forceFill([
                'status' => $status,
                'suspended_at' => null,
                'archived_at' => null,
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_REACTIVATED, $actor, ['status' => $status]);

            return $practitioner;
        });
    }

    /**
     * Archive a practitioner (non-destructive): the record is retained so
     * history stays intact; only the status changes.
     */
    public function archive(User $actor, Practitioner $practitioner): Practitioner
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner): Practitioner {
            $practitioner->forceFill([
                'status' => Practitioner::STATUS_INACTIVE,
                'archived_at' => now(),
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_ARCHIVED, $actor);

            return $practitioner;
        });
    }

    /**
     * Attach a credential to a practitioner (starts `pending`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addCredential(User $actor, Practitioner $practitioner, array $attributes): PractitionerCredential
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner, $attributes): PractitionerCredential {
            $credential = new PractitionerCredential;
            $credential->fill($attributes);
            $credential->forceFill([
                'practitioner_id' => $practitioner->getKey(),
                'status' => PractitionerCredential::STATUS_PENDING,
                'created_by' => (string) $actor->getKey(),
            ]);

            $credential->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_CREDENTIAL_ADDED, $actor, [
                'credential_id' => $credential->getKey(),
                'credential_type' => $credential->credential_type,
            ]);

            return $credential;
        });
    }

    /**
     * Explicitly verify a pending credential (audited; never automatic).
     */
    public function verifyCredential(
        User $actor,
        PractitionerCredential $credential,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
    ): PractitionerCredential {
        $tenantId = $this->assertCanVerify($actor);

        return $this->withTenant($tenantId, function () use ($actor, $credential, $expiresAt, $notes): PractitionerCredential {
            $practitioner = $this->resolvePractitioner($credential);
            $this->assertNotSelf($actor, $practitioner);

            if (! $credential->isPending()) {
                throw PractitionerException::credentialNotPending();
            }

            $credential->forceFill([
                'status' => PractitionerCredential::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => (string) $actor->getKey(),
                'verification_notes' => $notes,
                'expires_at' => $expiresAt ?? $credential->expires_at,
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_CREDENTIAL_VERIFIED, $actor, [
                'credential_id' => $credential->getKey(),
            ]);

            return $credential;
        });
    }

    /**
     * Reject a pending credential (non-destructive).
     */
    public function rejectCredential(User $actor, PractitionerCredential $credential, ?string $notes = null): PractitionerCredential
    {
        $tenantId = $this->assertCanVerify($actor);

        return $this->withTenant($tenantId, function () use ($actor, $credential, $notes): PractitionerCredential {
            $practitioner = $this->resolvePractitioner($credential);
            $this->assertNotSelf($actor, $practitioner);

            if (! $credential->isPending()) {
                throw PractitionerException::credentialNotPending();
            }

            $credential->forceFill([
                'status' => PractitionerCredential::STATUS_REJECTED,
                'verification_notes' => $notes,
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_CREDENTIAL_REJECTED, $actor, [
                'credential_id' => $credential->getKey(),
            ]);

            return $credential;
        });
    }

    /**
     * Revoke a verified credential (non-destructive): the row is retained so
     * the practitioner's credential history survives.
     */
    public function revokeCredential(User $actor, PractitionerCredential $credential, ?string $notes = null): PractitionerCredential
    {
        $tenantId = $this->assertCanVerify($actor);

        return $this->withTenant($tenantId, function () use ($actor, $credential, $notes): PractitionerCredential {
            $practitioner = $this->resolvePractitioner($credential);
            $this->assertNotSelf($actor, $practitioner);

            $credential->forceFill([
                'status' => PractitionerCredential::STATUS_REVOKED,
                'revoked_at' => now(),
                'verification_notes' => $notes ?? $credential->verification_notes,
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_CREDENTIAL_REVOKED, $actor, [
                'credential_id' => $credential->getKey(),
            ]);

            return $credential;
        });
    }

    /**
     * The definitive, server-side practitioner booking gate used by the
     * appointment / consultation / examination layers: verified, not expired,
     * and STILL an active clinical member of the current tenant. A suspended,
     * expired, archived or de-membered practitioner is refused immediately,
     * while their historical records remain intact.
     */
    public function isEligibleForBooking(Practitioner $practitioner, ?CarbonInterface $at = null): bool
    {
        if (! $practitioner->isVerifiedActive($at)) {
            return false;
        }

        $tenantId = $this->databases->current();

        if ($tenantId === null) {
            return false;
        }

        return $this->hasClinicalMembership((string) $practitioner->user_id, $tenantId, $at);
    }

    /**
     * Authorize a practitioner to practise at one branch (re-activates a
     * previously revoked authorization).
     */
    public function authorizeBranch(User $actor, Practitioner $practitioner, string $branchId): PractitionerBranchAuthorization
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner, $branchId): PractitionerBranchAuthorization {
            $authorization = PractitionerBranchAuthorization::query()
                ->where('practitioner_id', $practitioner->getKey())
                ->where('branch_id', $branchId)
                ->first() ?? new PractitionerBranchAuthorization;

            // practitioner_id / authorized_by / authorized_at are deliberately
            // not mass-assignable, so the identity must be forced explicitly.
            $authorization->forceFill([
                'practitioner_id' => $practitioner->getKey(),
                'branch_id' => $branchId,
                'status' => PractitionerBranchAuthorization::STATUS_ACTIVE,
                'authorized_by' => (string) $actor->getKey(),
                'authorized_at' => now(),
                'revoked_at' => null,
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_BRANCH_AUTHORIZED, $actor, [
                'branch_id' => $branchId,
            ]);

            return $authorization;
        });
    }

    /**
     * Revoke a practitioner's authorization for one branch (non-destructive).
     */
    public function revokeBranch(User $actor, Practitioner $practitioner, string $branchId): bool
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner, $branchId): bool {
            $authorization = PractitionerBranchAuthorization::query()
                ->where('practitioner_id', $practitioner->getKey())
                ->where('branch_id', $branchId)
                ->first();

            if ($authorization === null) {
                return false;
            }

            $authorization->forceFill([
                'status' => PractitionerBranchAuthorization::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_BRANCH_REVOKED, $actor, [
                'branch_id' => $branchId,
            ]);

            return true;
        });
    }

    /**
     * True when the practitioner holds an ACTIVE authorization for the branch.
     * Used by the schedule / booking layer; a branch-supplied id is never
     * trusted as authority.
     */
    public function isAuthorizedForBranch(Practitioner $practitioner, string $branchId, ?CarbonInterface $at = null): bool
    {
        return PractitionerBranchAuthorization::query()
            ->where('practitioner_id', $practitioner->getKey())
            ->where('branch_id', $branchId)
            ->where('status', PractitionerBranchAuthorization::STATUS_ACTIVE)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Server-side authorization & eligibility
    |--------------------------------------------------------------------------
    */

    private function assertCanManage(User $actor): string
    {
        return $this->assertPermission($actor, PermissionKey::PRACTITIONER_PROFILE_MANAGE, false);
    }

    private function assertCanVerify(User $actor): string
    {
        return $this->assertPermission($actor, PermissionKey::PRACTITIONER_CREDENTIAL_VERIFY, true);
    }

    private function assertPermission(User $actor, string $permission, bool $credentialOperation): string
    {
        if (! $actor->is_active) {
            throw $credentialOperation ? PractitionerException::notAReviewer() : PractitionerException::notAuthorized();
        }

        $tenantId = $this->tenants->currentTenantId($actor);

        if ($tenantId === null || ! $this->permissions->allows(
            $actor,
            $permission,
            AccessContext::forTenant($tenantId, null),
        )) {
            throw $credentialOperation ? PractitionerException::notAReviewer() : PractitionerException::notAuthorized();
        }

        return $tenantId;
    }

    /**
     * A practitioner may not verify or suspend their own record (separation of
     * duties, WS7.A §4).
     */
    private function assertNotSelf(User $actor, Practitioner $practitioner): void
    {
        if ((string) $practitioner->user_id === (string) $actor->getKey()) {
            throw PractitionerException::selfVerificationNotAllowed();
        }
    }

    private function resolvePractitioner(PractitionerCredential $credential): Practitioner
    {
        $practitioner = Practitioner::query()->whereKey($credential->practitioner_id)->first();

        if ($practitioner === null) {
            throw PractitionerException::unknownPractitioner();
        }

        return $practitioner;
    }

    /**
     * The target must be an ACTIVE clinical member of the tenant. Being listed
     * as a doctor only makes the practitioner *registrable* — it never verifies
     * their qualifications.
     */
    private function assertClinicalMembership(string $userId, string $tenantId): void
    {
        if (! $this->hasClinicalMembership($userId, $tenantId)) {
            throw PractitionerException::userNotEligible();
        }
    }

    private function hasClinicalMembership(string $userId, string $tenantId, ?CarbonInterface $at = null): bool
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->is_active) {
            return false;
        }

        $membership = TenantMembership::query()
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->with('roles')
            ->first();

        if ($membership === null) {
            return false;
        }

        $clinicalRoles = UserCategory::roleKeys(UserCategory::MEDICAL_STAFF);

        return $membership->roles->contains(
            static fn ($role): bool => in_array((string) $role->key, $clinicalRoles, true),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verified-tenant execution & audit
    |--------------------------------------------------------------------------
    */

    /**
     * Run the callback against the verified tenant's OWN database, binding it
     * transiently when it is not already the bound connection and releasing it
     * afterwards (even on failure), so no connection leaks.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(string $tenantId, Closure $callback): mixed
    {
        $wasBound = $this->databases->current() === $tenantId;

        try {
            if (! $wasBound) {
                $this->databases->configure($tenantId);
            }

            return $this->tenantTransaction($callback);
        } finally {
            if (! $wasBound) {
                $this->databases->forget();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(Practitioner $practitioner, string $action, ?User $actor, array $changes = []): void
    {
        PractitionerAuditLog::query()->create([
            'practitioner_id' => $practitioner->getKey(),
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'changes' => $changes === [] ? null : $changes,
            'occurred_at' => now(),
        ]);
    }
}
