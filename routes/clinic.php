<?php

use App\Filament\Clinic\Middleware\EnsureClinicTenantContext;
use App\Http\Controllers\Clinic\AssessmentController;
use App\Http\Controllers\Clinic\CommerceController;
use App\Http\Controllers\Clinic\ConsultationController;
use App\Http\Controllers\Clinic\EventController;
use App\Http\Controllers\Clinic\ExaminationController;
use App\Http\Controllers\Clinic\IntakeController;
use App\Http\Controllers\Clinic\LocationController;
use App\Http\Controllers\Clinic\PatientController;
use App\Http\Controllers\Clinic\PosController;
use App\Http\Controllers\Clinic\PractitionerController;
use App\Http\Controllers\Clinic\TeamController;
use App\Http\Controllers\Clinic\WalletController;
use App\Http\Controllers\Clinic\WorkspaceController;
use App\Http\Middleware\EnsureClinicPanelAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('clinic/workspace')->name('clinic.tailadmin.')
    ->middleware(['auth', 'auth.session', EnsureClinicPanelAccess::class, EnsureClinicTenantContext::class])
    ->controller(WorkspaceController::class)
    ->group(function (): void {
        Route::get('/', 'dashboard')->name('dashboard');
        Route::post('/switch-tenant', 'switchTenant')->name('switch-tenant');
        Route::post('/switch-branch', 'switchBranch')->name('switch-branch');
        // Pasien — a first-class TailAdmin registry (profile, lifecycle) rather
        // than a link back into Filament. The `{patient}` segment is constrained
        // to a ULID so it can never shadow `/patients/create` or `/{registry}`.
        Route::get('/patients/create', [PatientController::class, 'create'])->name('patients.create');
        Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
        Route::get('/patients/{patient}', [PatientController::class, 'show'])
            ->where('patient', '[0-9A-Za-z]{26}')->name('patients.show');
        Route::get('/patients/{patient}/edit', [PatientController::class, 'edit'])
            ->where('patient', '[0-9A-Za-z]{26}')->name('patients.edit');
        Route::put('/patients/{patient}', [PatientController::class, 'update'])
            ->where('patient', '[0-9A-Za-z]{26}')->name('patients.update');
        Route::post('/patients/{patient}/archive', [PatientController::class, 'archive'])
            ->where('patient', '[0-9A-Za-z]{26}')->name('patients.archive');
        Route::post('/patients/{patient}/restore', [PatientController::class, 'restore'])
            ->where('patient', '[0-9A-Za-z]{26}')->name('patients.restore');

        // Tenaga Ahli — the practitioner roster as a first-class TailAdmin
        // surface (register, profile lifecycle, branch authorization and
        // credentials) rather than a link back into Filament.
        Route::get('/practitioners/create', [PractitionerController::class, 'create'])->name('practitioners.create');
        Route::post('/practitioners', [PractitionerController::class, 'store'])->name('practitioners.store');
        Route::get('/practitioners/{practitioner}', [PractitionerController::class, 'show'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.show');
        Route::get('/practitioners/{practitioner}/edit', [PractitionerController::class, 'edit'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.edit');
        Route::put('/practitioners/{practitioner}', [PractitionerController::class, 'update'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.update');
        Route::post('/practitioners/{practitioner}/verify', [PractitionerController::class, 'verify'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.verify');
        Route::post('/practitioners/{practitioner}/suspend', [PractitionerController::class, 'suspend'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.suspend');
        Route::post('/practitioners/{practitioner}/reactivate', [PractitionerController::class, 'reactivate'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.reactivate');
        Route::post('/practitioners/{practitioner}/archive', [PractitionerController::class, 'archive'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.archive');
        Route::post('/practitioners/{practitioner}/branches', [PractitionerController::class, 'authorizeBranch'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.branches.authorize');
        Route::post('/practitioners/{practitioner}/branches/revoke', [PractitionerController::class, 'revokeBranch'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.branches.revoke');
        Route::post('/practitioners/{practitioner}/credentials', [PractitionerController::class, 'addCredential'])
            ->where('practitioner', '[0-9A-Za-z]{26}')->name('practitioners.credentials.store');
        Route::post('/practitioners/{practitioner}/credentials/{credential}/verify', [PractitionerController::class, 'verifyCredential'])
            ->where('practitioner', '[0-9A-Za-z]{26}')
            ->where('credential', '[0-9A-Za-z]{26}')->name('practitioners.credentials.verify');
        Route::post('/practitioners/{practitioner}/credentials/{credential}/reject', [PractitionerController::class, 'rejectCredential'])
            ->where('practitioner', '[0-9A-Za-z]{26}')
            ->where('credential', '[0-9A-Za-z]{26}')->name('practitioners.credentials.reject');
        Route::post('/practitioners/{practitioner}/credentials/{credential}/revoke', [PractitionerController::class, 'revokeCredential'])
            ->where('practitioner', '[0-9A-Za-z]{26}')
            ->where('credential', '[0-9A-Za-z]{26}')->name('practitioners.credentials.revoke');
        Route::get('/{intake}/create', [IntakeController::class, 'create'])
            ->whereIn('intake', ['appointments', 'examinations'])->name('intake.create');
        Route::post('/{intake}', [IntakeController::class, 'store'])
            ->whereIn('intake', ['appointments', 'examinations'])->name('intake.store');
        Route::get('/reports', 'reports')->name('reports');

        // Phase 11 — module surfaces: examination locations, events & bookings,
        // SINA sessions, the team & branch overview and the clinic settings hub.
        // Lokasi Pemeriksaan is a first-class TailAdmin CRUD surface (permanent,
        // temporary and event locations) rather than a link back into Filament.
        Route::get('/locations', [LocationController::class, 'index'])->name('locations');
        Route::get('/locations/create', [LocationController::class, 'create'])->name('locations.create');
        Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
        Route::get('/locations/{location}/edit', [LocationController::class, 'edit'])->name('locations.edit');
        Route::put('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::post('/locations/{location}/activate', [LocationController::class, 'activate'])->name('locations.activate');
        Route::post('/locations/{location}/deactivate', [LocationController::class, 'deactivate'])->name('locations.deactivate');

        Route::get('/events', [EventController::class, 'index'])->name('events');
        Route::get('/events/{location}', [EventController::class, 'show'])
            ->where('location', '[0-9A-Za-z]{26}')->name('events.show');
        Route::post('/events/{location}/bookings', [EventController::class, 'store'])
            ->where('location', '[0-9A-Za-z]{26}')->name('events.bookings.store');

        // Workstream 2 — Konsultasi tenaga ahli: the consultation book as a
        // first-class TailAdmin surface (book, open the dossier, move the
        // lifecycle) rather than a link back into Filament. The `{consultation}`
        // segment is ULID-constrained so it can never shadow `/consultations/create`
        // or the `/{registry}` list route.
        Route::get('/consultations/create', [ConsultationController::class, 'create'])->name('consultations.create');
        Route::post('/consultations', [ConsultationController::class, 'store'])->name('consultations.store');
        Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])
            ->where('consultation', '[0-9A-Za-z]{26}')->name('consultations.show');
        Route::post('/consultations/{consultation}/transition', [ConsultationController::class, 'transition'])
            ->where('consultation', '[0-9A-Za-z]{26}')->name('consultations.transition');

        Route::get('/sina', 'sina')->name('sina');

        // Tim & Cabang — the branch roster plus staff assignment on ONE branch
        // (assign / revoke), a first-class TailAdmin surface rather than a link
        // back into Filament.
        Route::get('/team', [TeamController::class, 'index'])->name('team');
        Route::get('/team/{branch}', [TeamController::class, 'show'])->name('team.show');
        Route::post('/team/{branch}/staff', [TeamController::class, 'assign'])->name('team.staff.assign');
        Route::post('/team/{branch}/staff/revoke', [TeamController::class, 'revoke'])->name('team.staff.revoke');

        Route::get('/settings', 'settings')->name('settings');

        // WS3 — TailAdmin point of sale, payment history and the AI credit wallet.
        Route::get('/pos', [PosController::class, 'index'])->name('pos');
        Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
        Route::get('/transactions', [PosController::class, 'transactions'])->name('transactions');
        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet');

        // WS3 — the product catalog, branch stock and the order book as
        // first-class TailAdmin surfaces (the Blade counterparts of
        // ProductResource, StockLevelResource and OrderResource), so no
        // catalog/stock/order action escapes into Filament. Every `{...}`
        // segment is ULID-constrained so it can never shadow `/create` or the
        // `/{registry}` list route, and static routes precede dynamic ones.
        Route::get('/products', [CommerceController::class, 'products'])->name('products');
        Route::get('/products/create', [CommerceController::class, 'productCreate'])->name('products.create');
        Route::post('/products', [CommerceController::class, 'productStore'])->name('products.store');
        Route::get('/products/{product}/edit', [CommerceController::class, 'productEdit'])
            ->where('product', '[0-9A-Za-z]{26}')->name('products.edit');
        Route::put('/products/{product}', [CommerceController::class, 'productUpdate'])
            ->where('product', '[0-9A-Za-z]{26}')->name('products.update');
        Route::post('/products/{product}/archive', [CommerceController::class, 'productArchive'])
            ->where('product', '[0-9A-Za-z]{26}')->name('products.archive');

        Route::get('/inventory', [CommerceController::class, 'inventory'])->name('inventory');
        Route::post('/inventory/{stock}/receive', [CommerceController::class, 'inventoryReceive'])
            ->where('stock', '[0-9A-Za-z]{26}')->name('inventory.receive');
        Route::post('/inventory/{stock}/adjust', [CommerceController::class, 'inventoryAdjust'])
            ->where('stock', '[0-9A-Za-z]{26}')->name('inventory.adjust');

        Route::get('/orders', [CommerceController::class, 'orders'])->name('orders');
        Route::get('/orders/{order}', [CommerceController::class, 'orderShow'])
            ->where('order', '[0-9A-Za-z]{26}')->name('orders.show');
        Route::post('/orders/{order}/fulfil', [CommerceController::class, 'orderFulfil'])
            ->where('order', '[0-9A-Za-z]{26}')->name('orders.fulfil');
        Route::post('/orders/{order}/cancel', [CommerceController::class, 'orderCancel'])
            ->where('order', '[0-9A-Za-z]{26}')->name('orders.cancel');
        Route::post('/orders/{order}/refund', [CommerceController::class, 'orderRefund'])
            ->where('order', '[0-9A-Za-z]{26}')->name('orders.refund');
        Route::post('/orders/{order}/pay', [CommerceController::class, 'orderPay'])
            ->where('order', '[0-9A-Za-z]{26}')->name('orders.pay');

        // Case detail, edit and the private image intake for one examination.
        // The `{examination}` segment is constrained so it can never shadow the
        // `/examinations/create` intake form or the `/{registry}` list route.
        Route::get('/examinations/{examination}', [ExaminationController::class, 'show'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('examinations.show');
        Route::get('/examinations/{examination}/edit', [ExaminationController::class, 'edit'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('examinations.edit');
        Route::put('/examinations/{examination}', [ExaminationController::class, 'update'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('examinations.update');
        Route::post('/examinations/{examination}/images', [ExaminationController::class, 'storeImage'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('examinations.images.store');
        Route::post('/examinations/{examination}/transition', [ExaminationController::class, 'transition'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('examinations.transition');

        // Phase 5 AI analysis on the TailAdmin case detail (no Filament escape
        // hatch). The domain service re-checks mode, consent, editability and
        // the enabled provider; the routes only carry the opaque ULIDs.
        Route::post('/examinations/{examination}/images/{image}/analysis', [ExaminationController::class, 'startAnalysis'])
            ->where('examination', '[0-9A-Za-z]{26}')
            ->where('image', '[0-9A-Za-z]{26}')->name('examinations.analysis.start');
        Route::post('/examinations/{examination}/analysis/{job}/retry', [ExaminationController::class, 'retryAnalysis'])
            ->where('examination', '[0-9A-Za-z]{26}')
            ->where('job', '[0-9A-Za-z]{26}')->name('examinations.analysis.retry');

        // Phase 7A — TailAdmin assessment recording for one examination.
        Route::get('/examinations/{examination}/assessment', [AssessmentController::class, 'show'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('assessments.show');
        Route::post('/examinations/{examination}/assessment/category', [AssessmentController::class, 'setCategory'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('assessments.category');
        Route::post('/examinations/{examination}/assessment/notes', [AssessmentController::class, 'saveItemNotes'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('assessments.notes');
        Route::post('/examinations/{examination}/assessment/evidence', [AssessmentController::class, 'attachEvidence'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('assessments.evidence');
        Route::post('/examinations/{examination}/assessment/phase', [AssessmentController::class, 'setPhase'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('assessments.phase');
        Route::post('/examinations/{examination}/assessment/brand', [AssessmentController::class, 'setBrand'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('assessments.brand');
        Route::post('/examinations/{examination}/assessment/draft', [AssessmentController::class, 'saveDraft'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('assessments.draft');
        Route::post('/examinations/{examination}/assessment/submit', [AssessmentController::class, 'submitForReview'])
            ->where('examination', '[0-9A-Za-z]{26}')->name('assessments.submit');

        // Phase 7B — TailAdmin reviewer inbox, human review decisions and the
        // explicit, private release of one report version to the patient portal.
        Route::get('/reviews', [AssessmentController::class, 'index'])->name('reviews.index');
        Route::get('/reviews/{assessment}', [AssessmentController::class, 'review'])
            ->where('assessment', '[0-9A-Za-z]{26}')->name('reviews.show');
        Route::post('/reviews/{assessment}/decision', [AssessmentController::class, 'decide'])
            ->where('assessment', '[0-9A-Za-z]{26}')->name('reviews.decide');
        Route::post('/reviews/{assessment}/reports/{report}/release', [AssessmentController::class, 'release'])
            ->where('assessment', '[0-9A-Za-z]{26}')
            ->where('report', '[0-9A-Za-z]{26}')->name('reviews.release');

        Route::get('/{registry}', 'index')->whereIn('registry', array_keys(WorkspaceController::REGISTRIES))->name('index');
    });
