<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\SavePatientRequest;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;

/**
 * Pasien — TailAdmin patient registry (Phase 11).
 *
 * Replaces the "open patient → Filament" escape hatch with a first-class
 * TailAdmin surface: the patient profile, its registration and status, and the
 * operational lifecycle (register, edit, archive, restore) are handled here
 * against the verified tenant's own isolated database (the {@see Patient}
 * model resolves that connection and fails closed without it).
 *
 * Authorization re-derives the canonical `patient.profile.read` /
 * `patient.profile.manage` permissions server-side through
 * {@see PatientResource} (never from client input), and every write goes
 * through {@see PatientRegistrar} so the audit trail is preserved. Patients are
 * archived, never deleted, so a patient's history across every branch of the
 * tenant survives.
 */
final class PatientController extends Controller
{
    public function create(Request $request, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        abort_unless(PatientResource::canCreate(), 403);

        $user = $request->user();

        return view('clinic.tailadmin.patient-create', [
            'tenantId' => $tenants->currentTenantId($user),
            'options' => $branches->eligibleOptions($user),
            'branchId' => $branches->currentBranchId($user),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function store(SavePatientRequest $request, PatientRegistrar $registrar): RedirectResponse
    {
        $registrar->create($request->validated() + ['registered_at' => now()], $request->user());

        return to_route('clinic.tailadmin.index', 'patients')
            ->with('success', __('lba.patients.created'));
    }

    /**
     * The patient profile: identity, contact, registration and (when the
     * Appointments module is present) the appointment history.
     */
    public function show(Request $request, string $patient): View
    {
        abort_unless(PatientResource::canViewAny(), 403);

        return view('clinic.tailadmin.patient-show', [
            'patient' => $this->resolvePatient($patient),
            'appointments' => $this->appointments($patient),
            'canManage' => PatientResource::canCreate(),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function edit(Request $request, string $patient, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        abort_unless(PatientResource::canCreate(), 403);

        $patient = $this->resolvePatient($patient);
        $user = $request->user();

        return view('clinic.tailadmin.patient-edit', [
            'patient' => $patient,
            'tenantId' => $tenants->currentTenantId($user),
            'options' => $branches->eligibleOptions($user),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function update(SavePatientRequest $request, string $patient, PatientRegistrar $registrar): RedirectResponse
    {
        $registrar->update($this->resolvePatient($patient), $request->validated(), $request->user());

        return to_route('clinic.tailadmin.patients.show', $patient)
            ->with('success', __('lba.patients.updated'));
    }

    public function archive(Request $request, string $patient, PatientRegistrar $registrar): RedirectResponse
    {
        abort_unless(PatientResource::canCreate(), 403);

        $registrar->archive($this->resolvePatient($patient), $request->user());

        return to_route('clinic.tailadmin.patients.show', $patient)
            ->with('success', __('lba.patients.archived'));
    }

    public function restore(Request $request, string $patient, PatientRegistrar $registrar): RedirectResponse
    {
        abort_unless(PatientResource::canCreate(), 403);

        $registrar->restore($this->resolvePatient($patient), $request->user());

        return to_route('clinic.tailadmin.patients.show', $patient)
            ->with('success', __('lba.patients.restored'));
    }

    /**
     * Resolve the target patient from the VERIFIED tenant database.
     *
     * Implicit route-model binding cannot be used here: `SubstituteBindings`
     * runs before the tenant connection is bound, so a `Patient` would fail
     * closed. The id is resolved explicitly inside the request instead — a
     * foreign or unknown id is simply not found (404), never widened.
     */
    private function resolvePatient(string $patient): Patient
    {
        return Patient::query()->whereKey($patient)->firstOrFail();
    }

    /**
     * The patient's appointment history, resolved only when the Appointments
     * module is installed (the Patients module never hard-depends on it).
     *
     * @return iterable<int, Appointment>
     */
    private function appointments(string $patient): iterable
    {
        if (! class_exists(Appointment::class)) {
            return collect();
        }

        return Appointment::query()
            ->with('branch')
            ->where('patient_id', $patient)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}
