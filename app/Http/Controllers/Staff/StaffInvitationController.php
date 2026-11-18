<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\Exceptions\StaffInvitationException;
use App\Platform\Access\StaffInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * WS4 P0 — public, unauthenticated staff invitation acceptance.
 *
 * The invited identity presents the single-use proof from the link the
 * Principal shared, then accepts the membership. Which path applies depends on
 * the invited identity:
 *
 *  - a NEW account (created by the invitation) sets its OWN password once;
 *  - an EXISTING account must be signed in as itself to accept, and its
 *    password is never reset by the link.
 *
 * The route is deliberately outside every Filament panel and outside tenant
 * context: it is the only pre-authentication write path, it only ever touches
 * central tables, and it fails closed on an unknown, expired, already-used or
 * platform-governance proof. After acceptance the identity signs in through the
 * normal `/login`.
 */
final class StaffInvitationController extends Controller
{
    public function __construct(private readonly StaffInvitation $invitations) {}

    public function show(Request $request, string $token): View
    {
        $membership = $this->invitations->findRedeemable($token);
        $requiresPasswordSetup = $membership !== null
            && $this->invitations->requiresPasswordSetup($membership);

        $actor = $request->user();
        $isInvitedIdentity = $membership !== null
            && $actor instanceof User
            && (string) $actor->getKey() === (string) $membership->user_id;

        return view('staff.invitation', [
            'token' => $token,
            'membership' => $membership,
            'requiresPasswordSetup' => $requiresPasswordSetup,
            'canAccept' => $membership !== null && ! $requiresPasswordSetup && $isInvitedIdentity,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $membership = $this->invitations->findRedeemable($token);

        if ($membership === null) {
            return back()->withErrors(['password' => __('lba.staff_invitation.invalid')]);
        }

        if ($this->invitations->requiresPasswordSetup($membership)) {
            $validated = $request->validate([
                'password' => ['required', 'string', 'confirmed', Password::min(12)],
            ]);

            try {
                $this->invitations->redeem($token, (string) $validated['password']);
            } catch (StaffInvitationException) {
                return back()->withErrors(['password' => __('lba.staff_invitation.invalid')]);
            }
        } else {
            try {
                $this->invitations->redeem($token, null, $request->user());
            } catch (StaffInvitationException $exception) {
                if ($exception->isAuthenticationRequired()) {
                    // A valid link for an established account: send the visitor
                    // back to the activation page, which now asks them to sign
                    // in as the invited identity.
                    return redirect()
                        ->route('staff.invitation.show', ['token' => $token])
                        ->withErrors(['password' => __('lba.staff_invitation.sign_in_required', [
                            'email' => (string) $membership->user?->email,
                        ])]);
                }

                return back()->withErrors(['password' => __('lba.staff_invitation.invalid')]);
            }
        }

        return redirect()->route('login')->with('status', __('lba.staff_invitation.activated'));
    }
}
