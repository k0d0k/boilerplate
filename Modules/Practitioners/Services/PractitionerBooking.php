<?php

namespace Modules\Practitioners\Services;

use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Patients\Models\Patient;
use Modules\Practitioners\Models\Practitioner;

/**
 * WS7.B — concurrency-safe practitioner slot reservation.
 *
 * The patient-facing and desk-facing booking paths both funnel through here so
 * a slot is reserved only when EVERY gate allows it:
 *   1. the practitioner is verified, active, still a clinical member of the
 *      verified tenant and AUTHORIZED for the branch (WS7.A gate);
 *   2. the requested instant range falls inside the practitioner's own practice
 *      schedule at that branch and is not blocked by leave/holiday/cancellation;
 *   3. the reservation is serialised by locking the shared parent rows (branch +
 *      practitioner) INSIDE the transaction, so two callers racing for the same
 *      final slot cannot both succeed — the loser re-reads the committed state
 *      and is refused (MySQL `SELECT … FOR UPDATE`, with deadlock retries).
 *
 * It never writes an appointment itself: the actual insert stays in
 * {@see AppointmentScheduler}, the single writer, which re-runs its own guards.
 * Authorization (WHO may book for WHOM) is enforced by the CALLER (the patient
 * page requires `booking.own` and forces the actor's own patient record; clinic
 * pages require `booking.manage`), exactly as for the legacy booking path.
 */
final class PractitionerBooking
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly AppointmentScheduler $scheduler,
        private readonly PractitionerAvailability $availability,
    ) {}

    /**
     * Reserve the next available slot for a practitioner at a branch.
     *
     * When `$end` is omitted the slot length is taken from the practitioner's
     * schedule at that moment (falling back to the branch default).
     */
    public function reserve(
        Practitioner $practitioner,
        TenantBranch $branch,
        Patient $patient,
        CarbonInterface $start,
        string $serviceType,
        ?CarbonInterface $end = null,
        ?string $notes = null,
        ?User $actor = null,
    ): Appointment {
        return $this->tenantTransaction(function () use ($practitioner, $branch, $patient, $start, $serviceType, $end, $notes, $actor): Appointment {
            // Serialise on the shared parent rows: the branch (capacity) and the
            // practitioner (their own double-booking). Held until commit.
            TenantBranch::query()->whereKey($branch->getKey())->lockForUpdate()->first();
            Practitioner::query()->whereKey($practitioner->getKey())->lockForUpdate()->first();

            if (! $practitioner->isVerifiedActive()) {
                throw AppointmentConflictException::staffNotEligible();
            }

            $startsAt = Carbon::parse($start);
            $endsAt = $end !== null
                ? Carbon::parse($end)
                : $startsAt->copy()->addMinutes(
                    $this->availability->slotMinutesAt($practitioner, $branch, $startsAt, $serviceType)
                        ?? $branch->effectiveSlotMinutes(),
                );

            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw AppointmentConflictException::invalidTimeRange();
            }

            if (! $this->availability->covers($practitioner, $branch, $startsAt, $endsAt, $serviceType)) {
                throw AppointmentConflictException::practitionerUnavailable();
            }

            return $this->scheduler->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $branch->getKey(),
                'staff_user_id' => (string) $practitioner->user_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'service_type' => $serviceType,
                'status' => Appointment::STATUS_SCHEDULED,
                'notes' => $notes,
            ], $actor);
        }, 3);
    }
}
