<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Access\PermissionKey;
use App\Platform\Api\Support\ApiResponse;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;

/**
 * Phase 8B — patient appointment booking, history and cancellation.
 *
 * Every write goes through the SAME {@see AppointmentScheduler} the clinic uses,
 * so all guards apply unchanged (tenant-local patient/branch existence, a valid
 * time range, no practitioner double-booking). The patient id is always the
 * acting identity's OWN record and the branch must be an ACTIVE branch of the
 * bound clinic — a patient can only ever book, read or cancel their own slots.
 */
final class AppointmentController extends PatientApiController
{
    use PresentsPatientApi;

    /** The single slot length the patient booking flow uses, matching the panel. */
    private const MODULE_DURATION_MINUTES = 30;

    public function index(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::BOOKING_OWN);
        $patient = $this->auth->patient();

        $query = Appointment::query()
            ->with('branch')
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('starts_at');

        if ($request->filled('status')) {
            $status = (string) $request->query('status');

            if (! in_array($status, Appointment::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Unknown appointment status.']);
            }

            $query->where('status', $status);
        }

        $paginator = $query
            ->paginate($this->perPage($request))
            ->through(fn (Appointment $appointment) => $this->appointmentArray($appointment));

        return ApiResponse::paginated($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::BOOKING_OWN);
        $patient = $this->auth->patient();

        $validated = $request->validate([
            'branch_id' => ['required', 'string'],
            'starts_at' => ['required', 'date', 'after:now'],
            'service_type' => ['required', 'string', 'in:'.implode(',', Appointment::SERVICES)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // The branch must be an ACTIVE branch of the bound clinic database.
        $branchExists = TenantBranch::query()->active()->whereKey($validated['branch_id'])->exists();

        if (! $branchExists) {
            throw ValidationException::withMessages(['branch_id' => 'The selected branch is not available.']);
        }

        $startsAt = Carbon::parse($validated['starts_at']);

        try {
            $appointment = app(AppointmentScheduler::class)->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $validated['branch_id'],
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(self::MODULE_DURATION_MINUTES),
                'service_type' => $validated['service_type'],
                'status' => Appointment::STATUS_SCHEDULED,
                'notes' => $validated['notes'] ?? null,
            ], $this->auth->user());
        } catch (AppointmentConflictException $exception) {
            return ApiResponse::error('booking_conflict', $exception->getMessage(), 422);
        }

        return ApiResponse::ok($this->appointmentArray($appointment->load('branch')), [], 201);
    }

    public function show(Request $request, string $appointment): JsonResponse
    {
        $this->auth->authorize(PermissionKey::BOOKING_OWN);
        $patient = $this->auth->patient();

        $model = $this->ownAppointment($appointment, (string) $patient->getKey());

        return ApiResponse::ok($this->appointmentArray($model));
    }

    public function cancel(Request $request, string $appointment): JsonResponse
    {
        $this->auth->authorize(PermissionKey::BOOKING_OWN);
        $patient = $this->auth->patient();

        $model = $this->ownAppointment($appointment, (string) $patient->getKey());

        try {
            app(AppointmentScheduler::class)->transition(
                $model,
                Appointment::STATUS_CANCELLED,
                $this->auth->user(),
                'Cancelled by patient via API',
            );
        } catch (AppointmentConflictException $exception) {
            return ApiResponse::error('invalid_transition', $exception->getMessage(), 409);
        }

        return ApiResponse::ok($this->appointmentArray($model->fresh()->load('branch')));
    }

    public function reschedule(Request $request, string $appointment): JsonResponse
    {
        $this->auth->authorize(PermissionKey::BOOKING_OWN);
        $patient = $this->auth->patient();

        $model = $this->ownAppointment($appointment, (string) $patient->getKey());

        // A terminal appointment (completed / cancelled / no-show) can never be
        // moved — only the lifecycle transition may change it.
        if ($model->isTerminal()) {
            return ApiResponse::error('invalid_transition', 'This appointment can no longer be rescheduled.', 409);
        }

        $validated = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'service_type' => ['nullable', 'string', 'in:'.implode(',', Appointment::SERVICES)],
        ]);

        $startsAt = Carbon::parse($validated['starts_at']);

        try {
            app(AppointmentScheduler::class)->reschedule($model, [
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(self::MODULE_DURATION_MINUTES),
                'service_type' => $validated['service_type'] ?? $model->service_type,
            ], $this->auth->user());
        } catch (AppointmentConflictException $exception) {
            return ApiResponse::error('booking_conflict', $exception->getMessage(), 422);
        }

        return ApiResponse::ok($this->appointmentArray($model->fresh()->load('branch')));
    }

    /**
     * Load an appointment that belongs to the acting patient, or 404. A foreign
     * id simply resolves to nothing, so existence is never leaked.
     */
    private function ownAppointment(string $appointmentId, string $patientId): Appointment
    {
        $appointment = Appointment::query()
            ->with('branch')
            ->whereKey($appointmentId)
            ->where('patient_id', $patientId)
            ->first();

        abort_if($appointment === null, 404);

        return $appointment;
    }
}
