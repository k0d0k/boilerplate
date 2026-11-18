<?php

namespace App\Platform\Access;

use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use App\Platform\Access\Exceptions\AccountEmailChangeException;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * WS9.E — secure self-service change of an account's LOGIN email.
 *
 * The new email is NEVER applied immediately. It is held in `pending_email`
 * and a signed link is delivered to the requested address; only when that link
 * is opened does the address become the login email. This means an unverified —
 * or attacker-supplied — address can never be used to sign in, and the current
 * password must be supplied before a change is even requested.
 *
 * The change is always for the actor's OWN account (resolved server-side) and
 * every request/verification is appended to the central, redacted audit trail.
 */
final class AccountEmailChanger
{
    public const MODULE_KEY = 'platform.accounts';

    public const ACTION_REQUESTED = 'account_email_change_requested';

    public const ACTION_VERIFIED = 'account_email_changed';

    /** How long the signed verification link stays valid. */
    public const CHANGE_TTL_HOURS = 24;

    /**
     * Hold a requested new login email and send the verification link to it.
     *
     * @throws AccountEmailChangeException when the current password is wrong
     */
    public function requestChange(User $actor, string $newEmail, string $currentPassword): void
    {
        if ($currentPassword === '') {
            throw AccountEmailChangeException::invalid();
        }

        if (! Hash::check($currentPassword, (string) $actor->getAuthPassword())) {
            $this->audit($actor, self::ACTION_REQUESTED, ModuleAuditLog::RESULT_DENIED, 'current_password_mismatch');

            throw AccountEmailChangeException::currentPasswordMismatch();
        }

        $newEmail = $this->normalise($newEmail);

        // The requested address is recorded but NOT used until verified.
        $actor->forceFill(['pending_email' => $newEmail])->save();

        Notification::route('mail', $newEmail)->notify(
            new VerifyAccountEmail($actor, $newEmail, $this->verificationUrl($actor, $newEmail)),
        );

        $this->audit($actor, self::ACTION_REQUESTED, ModuleAuditLog::RESULT_ALLOWED, null, $newEmail);
    }

    /**
     * Apply a pending email after the signed link is opened.
     *
     * Fails closed (returns false) when the identity is unknown or the address
     * does not match the one currently pending, so a link can never be replayed
     * against a different address or account.
     */
    public function verify(string $userId, string $email): bool
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return false;
        }

        $email = $this->normalise($email);

        if ($user->pending_email === null || ! hash_equals($this->normalise((string) $user->pending_email), $email)) {
            return false;
        }

        $user->forceFill([
            'email' => $email,
            'pending_email' => null,
            'email_verified_at' => now(),
        ])->save();

        $this->audit($user, self::ACTION_VERIFIED, ModuleAuditLog::RESULT_ALLOWED, null, $email);

        return true;
    }

    private function verificationUrl(User $actor, string $email): string
    {
        return URL::temporarySignedRoute('account.email.verify', now()->addHours(self::CHANGE_TTL_HOURS), [
            'user' => (string) $actor->getKey(),
            'email' => $email,
        ]);
    }

    private function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function audit(User $actor, string $action, string $result, ?string $reason = null, ?string $scopeKey = null): void
    {
        ModuleAuditLog::query()->create([
            'module_key' => self::MODULE_KEY,
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => $scopeKey ?? (string) $actor->getKey(),
            'actor_id' => $actor->getKey(),
            'metadata' => [],
        ]);
    }
}
