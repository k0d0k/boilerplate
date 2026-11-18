<?php

namespace App\Filament\Shared\Concerns;

use App\Platform\Access\Exceptions\PasswordChangeException;
use App\Platform\Access\PasswordChanger;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rules\Password;

/**
 * WS6.D — self-service "change my own password" behaviour shared by the
 * Principal, Clinic and Patient profile pages.
 *
 * The acting identity is always resolved server-side from the authenticated
 * session — never from a form field or route parameter — so a page can only
 * ever change ITS OWN password (no cross-user ID is accepted). The current
 * password must be supplied and is verified by {@see PasswordChanger}, which
 * also writes the central audit entry. The new value is validated with Laravel's
 * own password rules, hashed through the model cast and never echoed back.
 *
 * After a successful change the CURRENT device stays signed in while every
 * OTHER session is invalidated: the session's `password_hash_*` marker is
 * refreshed to the new hash for this request, and the `AuthenticateSession`
 * middleware will reject any session still carrying the previous hash.
 */
trait ChangesOwnPassword
{
    public ?string $current_password = null;

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public function changePassword(): void
    {
        $user = Filament::auth()->user();

        if ($user === null) {
            return;
        }

        $validated = $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
        ]);

        try {
            $changed = app(PasswordChanger::class)->change(
                $user,
                (string) $validated['current_password'],
                (string) $validated['password'],
            );
        } catch (PasswordChangeException $exception) {
            // A wrong current password is surfaced on the current-password
            // field; the new value is never persisted.
            $this->addError('current_password', __('lba.password_change.current_incorrect'));

            return;
        }

        $this->keepCurrentSessionSignedIn((string) $changed->getAuthPassword());

        $this->reset('current_password', 'password', 'password_confirmation');

        Notification::make()
            ->success()
            ->title(__('lba.password_change.saved'))
            ->send();
    }

    /**
     * Refresh the CURRENT session's stored password marker so this device is
     * not logged out on its next request, while every other device keeps the
     * stale marker and is rejected by `AuthenticateSession`.
     *
     * The raw hash is stored: the middleware accepts both the HMAC cookie
     * format and the raw hash for backward compatibility.
     */
    private function keepCurrentSessionSignedIn(string $passwordHash): void
    {
        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(
            'password_hash_'.config('auth.defaults.guard'),
            $passwordHash,
        );
    }
}
