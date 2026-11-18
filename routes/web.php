<?php

use App\Filament\Auth\StaffLogin;
use App\Http\Controllers\Account\AccountPhotoController;
use App\Http\Controllers\Account\EmailVerificationController;
use App\Http\Controllers\Staff\ForgotPasswordController;
use App\Http\Controllers\Staff\PasswordResetController;
use App\Http\Controllers\Staff\StaffInvitationController;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Public landing page
 |--------------------------------------------------------------------------
 |
 | The single public entry point for LBA Cloud. It is fully static (no
 | runtime dependencies) and only surfaces verified capabilities, clearly
 | separating what is available from what is still planned.
 |
 */
Route::get('/', function () {
    return view('landing');
})->name('home');

/*
 |--------------------------------------------------------------------------
 | Unified staff authentication (Phase 1B-1)
 |--------------------------------------------------------------------------
 |
 | Principal and Clinic staff share this single entry point. The page is a
 | Filament page rendered inside a real panel context, so the approved LBA
 | Cloud theme, form components and premium login layout are reused verbatim.
 | `panel:principal` only supplies that rendering context — the identity's
 | permitted panel is resolved server-side after authentication.
 |
 | The patient boundary stays separate at `/patient/login`.
 */
Route::get('/login', StaffLogin::class)
    ->middleware([
        'panel:principal',
        DispatchServingFilamentEvent::class,
    ])
    ->name('login');

/*
 |--------------------------------------------------------------------------
 | Staff invitation acceptance (WS4 P0)
 |--------------------------------------------------------------------------
 |
 | The invited staff identity opens the single-use link a Principal shared,
 | chooses their own password and activates the membership. This is a public,
 | panel-less and tenant-less boundary; once activated the account signs in
 | through the unified `/login` above.
 |
 */
Route::get('/staff/invitation/{token}', [StaffInvitationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('staff.invitation.show');

Route::post('/staff/invitation/{token}', [StaffInvitationController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('staff.invitation.accept');

/*
 |--------------------------------------------------------------------------
 | Password recovery (WS6.D)
 |--------------------------------------------------------------------------
 |
 | Public, panel-less and tenant-less recovery for every human identity
 | (Principal, Clinic staff and Patient). The one-time token is delivered to
 | the account's OWN verified email only — an initiating Super Admin never
 | learns or sets the new password. Both split token delivery and redemption
 | are throttled; the redeem endpoint fails closed on an unknown, expired,
 | replayed or suspended-account token.
 |
 */
Route::get('/staff/password/forgot', [ForgotPasswordController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('staff.password.forgot');

Route::post('/staff/password/forgot', [ForgotPasswordController::class, 'send'])
    ->middleware('throttle:10,1')
    ->name('staff.password.email');

Route::get('/staff/password/reset/{token}', [PasswordResetController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('staff.password.reset');

Route::post('/staff/password/reset/{token}', [PasswordResetController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('staff.password.reset.store');

/*
 |--------------------------------------------------------------------------
 | Account login-email verification (WS9.E)
 |--------------------------------------------------------------------------
 |
 | A signed, short-lived link delivered to a REQUESTED new login email. Opening
 | it proves ownership of that mailbox and only THEN switches the account's login
 | email; the previous address stays authoritative until this succeeds. The
 | controller fails closed on an unknown user or a mismatched pending address.
 |
 */
Route::get('/account/email/verify/{user}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->name('account.email.verify');

/*
 |--------------------------------------------------------------------------
 | Account profile photo (WS9.E)
 |--------------------------------------------------------------------------
 |
 | A private, authenticated streaming endpoint for the SIGNED-IN account's own
 | photo. The identity comes from the session only, so it can never be used to
 | read another account's photo, and the file never has a public URL.
 |
 */
Route::get('/account/photo', AccountPhotoController::class)
    ->middleware('auth')
    ->name('account.photo');

require __DIR__.'/principal.php';
require __DIR__.'/clinic.php';
