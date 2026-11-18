<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Platform\Access\Exceptions\PasswordResetException;
use App\Platform\Access\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * WS6.D — public, unauthenticated redemption of a one-time recovery token.
 *
 * The identity presents the single-use proof delivered to their own email and
 * chooses a NEW password. The token is single-use and short-lived: it is
 * deleted the moment it is redeemed, so a link can never be replayed. An
 * expired, replayed or unknown token (and a suspended identity) fails closed
 * with a uniform message. The new password is hashed through the model cast;
 * no plaintext credential is ever persisted, logged, returned or emailed.
 */
final class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordReset $resets) {}

    public function show(Request $request, string $token): View
    {
        return view('staff.password-reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
        ]);

        try {
            $this->resets->reset([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'token' => $token,
            ]);
        } catch (PasswordResetException) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('lba.password_reset.invalid')]);
        }

        return redirect()->route('login')->with('status', __('lba.password_reset.reset_done'));
    }
}
