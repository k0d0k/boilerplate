<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\Exceptions\PasswordResetException;
use App\Platform\Access\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WS6.D — public self-service password recovery request.
 *
 * A Principal, Clinic or Patient identity who has lost their password asks for
 * a one-time recovery link here. The link is delivered ONLY to the account's
 * own verified email; the response is deliberately identical whether or not an
 * account exists, so the endpoint cannot be used to enumerate identities. The
 * route carries no authenticated session and is throttled.
 */
final class ForgotPasswordController extends Controller
{
    public function __construct(private readonly PasswordReset $resets) {}

    public function show(): View
    {
        return view('staff.forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $target = User::query()->where('email', $validated['email'])->first();

        if ($target instanceof User && $target->is_active) {
            try {
                // Unauthenticated self-service recovery: no acting identity.
                $this->resets->request($target, null);
            } catch (PasswordResetException) {
                // Fail closed and silently: never reveal whether an account
                // exists or whether a link was actually dispatched.
            }
        }

        // The same neutral confirmation is shown in every case to avoid
        // disclosing account existence.
        return back()->with('status', __('lba.password_reset.request_sent'));
    }
}
