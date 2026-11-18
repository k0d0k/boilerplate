<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Illuminate\Support\Str;
use Modules\Patients\Exceptions\PatientLinkException;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;

/**
 * Phase 8A — secure patient portal account binding.
 *
 * `patients.user_id` binds a tenant patient record to a central user account,
 * and that binding is what lets {@see PatientIdentity} resolve a self-service
 * identity. It is therefore a security-sensitive fact and must NEVER be
 * reachable from untrusted self-service input:
 *
 *  - the column is not mass-assignable on the model, and this is the ONLY code
 *    that ever writes it, via an explicit `forceFill`;
 *  - binding requires a clinic-issued, single-use, expiring INVITATION proof —
 *    the presented secret is hashed and compared, so an account can never be
 *    attached by guessing, and NEVER by matching an email or a phone number;
 *  - issuing a proof is an AUTHORIZED clinic action: the actor must hold
 *    `patient.profile.manage` for the VERIFIED tenant context;
 *  - every issue / bind / unbind is appended to the patient audit trail with the
 *    acting identity and the time.
 *
 * Every write runs inside a verified tenant transaction, so a binding can only
 * ever land on the active tenant's isolated database.
 */
final class PatientAccountLinker
{
    use RunsVerifiedTenantTransactions;

    /** How long a clinic-issued invitation proof stays valid. */
    public const INVITATION_TTL_DAYS = 7;

    public function __construct(
        private readonly ManagesTenantContext $tenants,
        private readonly ChecksPermissions $permissions,
    ) {}

    /**
     * Clinic-side, authorized: issue a single-use, expiring invitation proof for
     * ONE patient record and return the raw code. Only the hash is stored, so the
     * raw proof exists exactly once — in the response to the authorized actor.
     *
     * @return string the raw invitation code (shown once, never persisted)
     */
    public function issueInvitation(Patient $patient, ?User $actor): string
    {
        $this->assertAuthorized($actor);

        $raw = Str::random(64);

        return $this->tenantTransaction(function () use ($patient, $actor, $raw): string {
            $patient->forceFill([
                'portal_invite_token' => self::hash($raw),
                'portal_invite_expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
                'portal_invite_issued_by' => (string) $actor?->getKey(),
            ])->save();

            $this->audit($patient, PatientAuditLog::ACTION_PORTAL_INVITE_ISSUED, $actor);

            return $raw;
        });
    }

    /**
     * Redeem a clinic-issued invitation proof and bind the acting account to the
     * matching patient record. Matching is by the token proof ONLY — never by
     * email or phone — and a record already bound to a DIFFERENT account is
     * refused (fails closed).
     */
    public function redeemInvitation(User $account, string $raw): Patient
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw PatientLinkException::invitationInvalid();
        }

        $patient = Patient::query()
            ->where('portal_invite_token', self::hash($raw))
            ->whereNotNull('portal_invite_expires_at')
            ->where('portal_invite_expires_at', '>=', now())
            ->first();

        if ($patient === null) {
            throw PatientLinkException::invitationInvalid();
        }

        if ($patient->isLinked() && (string) $patient->user_id !== (string) $account->getKey()) {
            throw PatientLinkException::alreadyLinked();
        }

        return $this->tenantTransaction(function () use ($patient, $account): Patient {
            $patient->forceFill([
                'user_id' => (string) $account->getKey(),
                'portal_invite_token' => null,
                'portal_invite_expires_at' => null,
                'portal_invite_issued_by' => null,
            ])->save();

            $this->audit($patient, PatientAuditLog::ACTION_PORTAL_ACCOUNT_LINKED, $account);

            return $patient;
        });
    }

    /**
     * Clinic-side, authorized: revoke a portal account binding. The patient
     * record is never deleted — only the binding is removed.
     */
    public function unlink(Patient $patient, ?User $actor): void
    {
        $this->assertAuthorized($actor);

        $this->tenantTransaction(function () use ($patient, $actor): void {
            $patient->forceFill(['user_id' => null])->save();

            $this->audit($patient, PatientAuditLog::ACTION_PORTAL_ACCOUNT_UNLINKED, $actor);
        });
    }

    /**
     * Re-derive authorization on the SERVER: the actor must be active and hold
     * `patient.profile.manage` for the VERIFIED tenant. A role held elsewhere can
     * never authorize a binding.
     */
    private function assertAuthorized(?User $actor): void
    {
        if ($actor === null || ! $actor->is_active) {
            throw PatientLinkException::notAuthorized();
        }

        $tenantId = $this->tenants->currentTenantId($actor);

        if ($tenantId === null || ! $this->permissions->allows(
            $actor,
            PermissionKey::PATIENT_PROFILE_MANAGE,
            AccessContext::forTenant($tenantId, null),
        )) {
            throw PatientLinkException::notAuthorized();
        }
    }

    private function audit(Patient $patient, string $action, ?User $actor): void
    {
        PatientAuditLog::query()->create([
            'patient_id' => $patient->getKey(),
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'changes' => null,
            'occurred_at' => now(),
        ]);
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
