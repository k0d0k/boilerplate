<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Platform\Access\AccountEmailChanger;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WS9.E — public redemption of a pending login-email change.
 *
 * The signed link is delivered to the REQUESTED new address; opening it proves
 * ownership of that mailbox and switches the account's login email. The route
 * is signature-protected and short-lived, and the service fails closed when the
 * address does not match the one currently pending — so a link can never be
 * replayed or forged into an account takeover.
 */
final class EmailVerificationController extends Controller
{
    public function __construct(private readonly AccountEmailChanger $changer) {}

    public function __invoke(Request $request, string $user): View
    {
        $verified = $this->changer->verify($user, (string) $request->query('email', ''));

        return view('staff.account-email-verified', ['verified' => $verified]);
    }
}
