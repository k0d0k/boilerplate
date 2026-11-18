<?php

namespace App\Platform\Access;

use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Exceptions\PasswordResetException;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;

/**
 * WS6.D — audited, one-time password recovery.
 *
 * Recovery deliberately reuses Laravel's existing password broker: the single
 * `password_reset_tokens` store holds only a SHA-256 HASH of a short-lived,
 * single-use token (60-minute expiry, 60-second re-issue throttle), and the
 * token is deleted the moment it is redeemed — so a link can never be replayed.
 * This service adds the two properties the framework broker does not carry:
 *
 *  - an EXPLICIT capability check before a Super Admin may start a reset for
 *    ANOTHER human identity (self-service recovery is always allowed), and
 *  - a central, redacted audit entry for both the initiate and the redeem,
 *    whether allowed or denied.
 *
 * Crucially the raw token is NEVER returned to any caller: it is delivered only
 * to the account's own verified email address. A Super Admin therefore cannot
 * learn or set the target's new password, and this flow never touches a staff
 * invitation token (see {@see StaffInvitation}) — the two are independent.
 */
final class PasswordReset
{
    public const ACTION_REQUESTED = 'password_reset_requested';

    public const ACTION_COMPLETED = 'password_reset_completed';

    public function __construct(private readonly AuthorizesPrincipal $principals) {}

    /**
     * Start a password reset for $target and deliver the one-time link to the
     * account's own email. The raw proof is never returned, so even the
     * initiating Super Admin cannot learn or set the new password.
     *
     * @param  User|null  $actor  the acting identity; null for unauthenticated
     *                            self-service recovery
     *
     * @throws PasswordResetException
     */
    public function request(User $target, ?User $actor = null): void
    {
        if (! $target->is_active) {
            $this->deny($target, $actor, self::ACTION_REQUESTED, 'inactive', PasswordResetException::inactive());
        }

        if ($actor instanceof User && ! $this->mayInitiateFor($actor, $target)) {
            $this->deny($target, $actor, self::ACTION_REQUESTED, 'not_permitted', PasswordResetException::notPermitted());
        }

        $status = $this->broker()->sendResetLink(['email' => (string) $target->email]);

        match ($status) {
            Password::RESET_LINK_SENT => $this->audit(
                $target,
                $actor ?? $target,
                self::ACTION_REQUESTED,
                ModuleAuditLog::RESULT_ALLOWED,
            ),
            Password::RESET_THROTTLED => $this->deny(
                $target,
                $actor,
                self::ACTION_REQUESTED,
                'throttled',
                PasswordResetException::throttled(),
            ),
            default => $this->deny(
                $target,
                $actor,
                self::ACTION_REQUESTED,
                'unknown_recipient',
                PasswordResetException::invalid(),
            ),
        };
    }

    /**
     * Redeem a one-time recovery token and set the identity's OWN new password.
     * The token is single-use and expires; a suspended identity is refused.
     *
     * @param  array<string, mixed>  $credentials  email, password,
     *                                             password_confirmation, token
     *
     * @throws PasswordResetException
     */
    public function reset(array $credentials, ?User $actor = null): User
    {
        $email = trim((string) ($credentials['email'] ?? ''));
        $target = $email === '' ? null : User::query()->where('email', $email)->first();

        if (! $target instanceof User || ! $target->is_active) {
            $this->audit($target, $actor, self::ACTION_COMPLETED, ModuleAuditLog::RESULT_DENIED, 'invalid_or_inactive');

            throw PasswordResetException::invalid();
        }

        $status = $this->broker()->reset($credentials, function (User $user, string $password): void {
            // The `password` cast hashes the value; no plaintext is persisted.
            $user->forceFill([
                'password' => $password,
                'must_set_password' => false,
            ])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            $this->audit($target, $actor, self::ACTION_COMPLETED, ModuleAuditLog::RESULT_DENIED, 'invalid_token');

            throw PasswordResetException::invalid();
        }

        $this->audit($target, $actor ?? $target, self::ACTION_COMPLETED, ModuleAuditLog::RESULT_ALLOWED);

        return $target->refresh();
    }

    /**
     * Whether $actor may start a reset for $target: always for themselves, and
     * for ANOTHER human identity only when the acting platform identity holds
     * the access-management capability.
     */
    public function mayInitiateFor(User $actor, User $target): bool
    {
        if ((string) $actor->getKey() === (string) $target->getKey()) {
            return true;
        }

        return $actor->is_active
            && $this->principals->can($actor, PermissionKey::PLATFORM_RBAC_MANAGE);
    }

    private function deny(User $target, ?User $actor, string $action, string $reason, PasswordResetException $exception): never
    {
        $this->audit($target, $actor, $action, ModuleAuditLog::RESULT_DENIED, $reason);

        throw $exception;
    }

    private function broker(): PasswordBroker
    {
        return Password::broker();
    }

    private function audit(
        ?User $target,
        ?User $actor,
        string $action,
        string $result,
        ?string $reason = null,
    ): void {
        ModuleAuditLog::query()->create([
            'module_key' => 'platform.rbac',
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => $target !== null ? (string) $target->getKey() : null,
            'actor_id' => $actor?->getKey(),
            'metadata' => [],
        ]);
    }
}
