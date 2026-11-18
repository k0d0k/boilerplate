<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;

final class IntakeController extends Controller
{
    public function create(Request $request, string $intake, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        $resource = $this->resource($intake);
        abort_unless($resource::canCreate(), 403);
        $user = $request->user();
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = $filters['search'] ?? '';

        return view('clinic.tailadmin.intake', [
            'intake' => $intake,
            'title' => $resource::getNavigationLabel(),
            'tenantId' => $tenants->currentTenantId($user),
            'branchId' => $branches->currentBranchId($user),
            'branchOptions' => $branches->eligibleOptions($user),
            'patients' => Patient::query()->active()
                ->when($search !== '', fn ($query) => $query->whereLike('full_name', "%{$search}%"))
                ->orderBy('full_name')->orderBy('id')->limit(50)->get(['id', 'full_name', 'patient_number']),
            'search' => $search,
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function store(Request $request, string $intake, ManagesTenantContext $tenants, ManagesBranchContext $branches, AppointmentScheduler $appointments, ExaminationRegistrar $examinations): RedirectResponse
    {
        $resource = $this->resource($intake);
        $user = $request->user();
        abort_unless($resource::canCreate() && $tenants->matchesCurrent($user, $request->string('tenant_id')->toString()), 403);

        $branchIds = array_column($branches->eligibleOptions($user), 'id');
        $data = $request->validate([
            'patient_id' => ['required', 'ulid', Rule::exists(Patient::class, 'id')->where('status', Patient::STATUS_ACTIVE)],
            'branch_id' => ['required', 'ulid', Rule::in($branchIds)],
            ...($intake === 'appointments' ? [
                'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
                'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
                'service_type' => ['required', Rule::in(Appointment::SERVICES)],
                'notes' => ['nullable', 'string', 'max:1000'],
            ] : [
                'mode' => ['required', Rule::in(Examination::MODES)],
                'instrument' => ['nullable', 'string', 'max:120'],
                'magnification' => ['nullable', 'string', 'max:60'],
                'record_consent' => ['sometimes', 'boolean'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]),
        ]);

        try {
            if ($intake === 'appointments') {
                $appointments->create($data, $user);
            } else {
                $examinations->create($data, $user);
            }
        } catch (AppointmentConflictException|ExaminationException $exception) {
            throw ValidationException::withMessages(['patient_id' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.index', $intake);
    }

    private function resource(string $intake): string
    {
        return $intake === 'appointments' ? AppointmentResource::class : ExaminationResource::class;
    }
}
