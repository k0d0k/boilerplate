<?php

use App\Http\Controllers\Api\Patient\AppointmentController;
use App\Http\Controllers\Api\Patient\AuthController;
use App\Http\Controllers\Api\Patient\ClinicController;
use App\Http\Controllers\Api\Patient\ConsultationController;
use App\Http\Controllers\Api\Patient\DirectoryController;
use App\Http\Controllers\Api\Patient\OrderController as PatientOrderController;
use App\Http\Controllers\Api\Patient\ProfileController;
use App\Http\Controllers\Api\Patient\ReportController;
use App\Http\Controllers\Api\Patient\ShopController;
use App\Http\Controllers\Api\Patient\SinaChatController;
use App\Http\Controllers\Api\Patient\TokenWalletController;
use App\Http\Controllers\Api\Payments\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Patient REST API (Phase 8B)
|--------------------------------------------------------------------------
|
| Mobile-facing contract for the patient portal. Every authenticated call runs
| through the `auth:api` bearer-token guard, which resolves a token bound to
| exactly ONE clinic, and the `patient.api` middleware, which binds that
| clinic's tenant database for the request. Isolation therefore comes from the
| token itself — a client-supplied clinic id is never trusted as authority.
|
| Responses share one envelope (ApiResponse): success is `{data, meta?}`, and
| every failure is `{error:{code, message, details?}}` (ApiExceptionRenderer).
|
*/

Route::prefix('v1/patient')->group(function (): void {

    // Public: exchange credentials for a clinic-scoped bearer token. Rate
    // limited per IP to blunt credential stuffing.
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:patient-api-login')
        ->name('api.patient.login');

    // Authenticated: a valid, clinic-bound token is required for everything else.
    Route::middleware(['auth:api', 'patient.api', 'throttle:patient-api'])->group(function (): void {

        Route::get('me', [AuthController::class, 'me'])->name('api.patient.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('api.patient.logout');

        // Clinic selection: list eligible clinics / re-scope the token.
        Route::get('clinics', [ClinicController::class, 'index'])->name('api.patient.clinics.index');
        Route::post('clinics/select', [ClinicController::class, 'select'])->name('api.patient.clinics.select');

        // Own profile (self-service contact fields only).
        Route::get('profile', [ProfileController::class, 'show'])->name('api.patient.profile.show');
        Route::put('profile', [ProfileController::class, 'update'])->name('api.patient.profile.update');

        // Brand and branch directory of the bound clinic.
        Route::get('directory/brands', [DirectoryController::class, 'brands'])->name('api.patient.directory.brands');
        Route::get('directory/branches', [DirectoryController::class, 'branches'])->name('api.patient.directory.branches');
        Route::get('directory/branches/{branch}', [DirectoryController::class, 'branch'])->name('api.patient.directory.branch');
        Route::get('directory/branches/{branch}/availability', [DirectoryController::class, 'availability'])->name('api.patient.directory.availability');

        // Own appointments: history, booking, detail, rescheduling, cancellation.
        Route::get('appointments', [AppointmentController::class, 'index'])->name('api.patient.appointments.index');
        Route::post('appointments', [AppointmentController::class, 'store'])->name('api.patient.appointments.store');
        Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('api.patient.appointments.show');
        Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('api.patient.appointments.reschedule');
        Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('api.patient.appointments.cancel');

        // Approved and EXPLICITLY RELEASED reports, plus the private PDF stream.
        Route::get('reports', [ReportController::class, 'index'])->name('api.patient.reports.index');
        Route::get('reports/{report}/download', [ReportController::class, 'download'])->name('api.patient.reports.download');

        // Commerce (WS3): the bound clinic's catalog, own orders and checkout.
        Route::get('shop/products', [ShopController::class, 'index'])->name('api.patient.shop.products.index');
        Route::get('shop/products/{product}', [ShopController::class, 'show'])->name('api.patient.shop.products.show');

        Route::get('orders', [PatientOrderController::class, 'index'])->name('api.patient.orders.index');
        Route::post('orders', [PatientOrderController::class, 'store'])->name('api.patient.orders.store');
        Route::get('orders/{order}', [PatientOrderController::class, 'show'])->name('api.patient.orders.show');
        Route::post('orders/{order}/pay', [PatientOrderController::class, 'pay'])->name('api.patient.orders.pay');

        // Own consultations: book a human expert session, history, detail,
        // transcript and cancellation.
        Route::get('consultations', [ConsultationController::class, 'index'])->name('api.patient.consultations.index');
        Route::post('consultations', [ConsultationController::class, 'store'])->name('api.patient.consultations.store');
        Route::get('consultations/{consultation}', [ConsultationController::class, 'show'])->name('api.patient.consultations.show');
        Route::get('consultations/{consultation}/messages', [ConsultationController::class, 'messages'])->name('api.patient.consultations.messages');
        Route::post('consultations/{consultation}/cancel', [ConsultationController::class, 'cancel'])->name('api.patient.consultations.cancel');

        // SINA consultation chat: consent, session, turn, wallet and usage.
        Route::get('sina', [SinaChatController::class, 'show'])->name('api.patient.sina.show');
        Route::post('sina/consent', [SinaChatController::class, 'consent'])->name('api.patient.sina.consent');
        Route::post('sina/session', [SinaChatController::class, 'start'])->name('api.patient.sina.start');
        Route::post('sina/messages', [SinaChatController::class, 'send'])->name('api.patient.sina.send');
        Route::get('wallet', [TokenWalletController::class, 'show'])->name('api.patient.wallet.show');
        Route::get('wallet/usage', [TokenWalletController::class, 'usage'])->name('api.patient.wallet.usage');
        Route::get('sina/wallet', [TokenWalletController::class, 'show'])->name('api.patient.sina.wallet');
        Route::get('sina/usage', [TokenWalletController::class, 'usage'])->name('api.patient.sina.usage');
    });
});

/*
|--------------------------------------------------------------------------
| Payment gateway webhooks (WS3)
|--------------------------------------------------------------------------
|
| Public, unauthenticated notification endpoints — one per provider. They carry
| NO tenant context and NO session; authority comes from the gateway signature
| (verified by the driver) and the central payment-intent registry, which maps
| the notification back to the correct tenant database. Processing is
| idempotent, so a gateway retry is harmless.
|
*/

Route::prefix('v1/payments')->group(function (): void {
    Route::post('{provider}/webhook', [WebhookController::class, 'handle'])
        ->middleware('throttle:120,1')
        ->name('api.payments.webhook');
});
