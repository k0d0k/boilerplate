<?php

namespace App\Platform\Access;

use App\Models\PatientApiToken;
use App\Models\User;
use App\Platform\Access\Exceptions\PasswordChangeException;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Support\Facades\Hash;

/**
 * WS6.D — self-service password change.
 *
 * The ONLY way an authenticated identity changes its OWN password. The current
 * password is verified against the stored hash before anything is written, so a
 * hijacked session (or a forged request) cannot silently rotate a credential.
 * The new password is stored through the model's `hashed` cast — no plaintext
 * is ever persisted, logged, returned or emailed — and the account's
 * `must_set_password` flag is cleared.
 *
 * On success every credential derived from the previous password is revoked:
 * patient REST tokens are marked revoked, and every OTHER web session is
 * invalidated by the `AuthenticateSession` middleware, because the password
 * hash it compares against has changed. The current session is preserved by the
 * caller (see the Filament change-password pages).
 *
 * Every attempt — allowed or denied — is appended to the central, redacted
 * audit trail.
 */
final class PasswordChanger
{
    public const ACTION = 'password_changed';

    /**
     * Change one identity's OWN password after verifying the current one.
     *
     * @throws PasswordChangeException when the current password is wrong
     */
    public function change(User $user, string $currentPassword, string $newPassword): User
    {
        if ($currentPassword === '' || $newPassword === '') {
            throw PasswordChangeException::invalid();
        }

        if (! Hash::check($currentPassword, (string) $user->getAuthPassword())) {
            $this->audit($user, ModuleAuditLog::RESULT_DENIED, 'current_password_mismatch');

            throw PasswordChangeException::currentPasswordMismatch();
        }

        $user->forceFill([
            'password' => $newPassword,
            'must_set_password' => false,
        ])->save();

        $this->revokeDerivedCredentials($user);

        $this->audit($user, ModuleAuditLog::RESULT_ALLOWED);

        return $user->refresh();
    }

    /**
     * Revoke credential material derived from the previous password: patient
     * REST API tokens become unusable immediately. Web sessions are handled by
     * `AuthenticateSession` (the stored password hash no longer matches).
     */
    private function revokeDerivedCredentials(User $user): void
    {
        PatientApiToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function audit(User $user, string $result, ?string $reason = null): void
    {
        ModuleAuditLog::query()->create([
            'module_key' => 'platform.rbac',
            'action' => self::ACTION,
            'result' => $result,
            'reason' => $reason,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => (string) $user->getKey(),
            'actor_id' => $user->getKey(),
            'metadata' => [],
        ]);
    }
}
