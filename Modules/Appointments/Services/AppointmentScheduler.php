<?php

namespace Modules\Appointments\Services;

use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\AppointmentStatusHistory;
use Modules\Patients\Models\Patient;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerRegistry;

/**
 * Phase 3 — appointment booking, rescheduling and status lifecycle.
 *
 * Every mutation runs inside a transaction and re-validates the booking against
 * the verified tenant database:
 *  - the patient and branch must exist in the CURRENT tenant database (a foreign
 *    id can never resolve, so a cross-tenant booking is impossible);
 *  - the assigned staff member, when set, must be an ACTIVE member of the
 *    current tenant (central registry);
 *  - an occupying status may not overlap another occupying appointment of the
 *    same assigned practitioner (basic double-booking prevention).
 *
 * WS7.B — concurrency safety: before the overlap / capacity checks the shared
 * parent rows are LOCKED (`SELECT … FOR UPDATE`) inside the same transaction —
 * the branch (branch-capacity races) and, when the assignee is a REGISTERED
 * practitioner, the practitioner row (per-practitioner double-booking). On MySQL
 * this serialises simultaneous reservations so a pre-submit query can never be
 * bypassed by a racing insert; on SQLite (used by the sandbox tests) the whole
 * database serialises writes anyway. When the assignee is a registered
 * practitioner this also re-derives the WS7.A booking gate server-side.
 *
 * Every status change is appended to the appointment's status history, so the
 * lifecycle is auditable.
 */
final class AppointmentScheduler
{
    use RunsVerifiedTenantTransactions;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Appointment
    {
        // Retry on a transient deadlock (MySQL) so a racing reservation that
        // loses the lock simply re-runs its guard against the committed state.
        return $this->tenantTransaction(function () use ($attributes, $actor): Appointment {
            $appointment = new Appointment;
            $appointment->fill($attributes);

            if (! in_array($appointment->status, Appointment::STATUSES, true)) {
                $appointment->status = Appointment::STATUS_SCHEDULED;
            }

            $appointment->created_by = $actor?->getKey();

            $this->guard($appointment);

            $appointment->save();

            $this->recordStatus($appointment, null, (string) $appointment->status, $actor);

            return $appointment;
        }, 3);
    }

    /**
     * Reschedule an appointment (date/time, service and assignment).
     *
     * The status is deliberately NOT editable here: the lifecycle only moves
     * through {@see self::transition()}, which validates each transition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function reschedule(Appointment $appointment, array $attributes, ?User $actor = null): Appointment
    {
        return $this->tenantTransaction(function () use ($appointment, $attributes): Appointment {
            $appointment->fill($attributes);

            $this->guard($appointment);

            $appointment->save();

            return $appointment;
        }, 3);
    }

    public function transition(Appointment $appointment, string $to, ?User $actor = null, ?string $note = null): Appointment
    {
        return $this->tenantTransaction(function () use ($appointment, $to, $actor, $note): Appointment {
            $from = (string) $appointment->status;

            if (! $appointment->canTransitionTo($to)) {
                throw AppointmentConflictException::invalidTransition($from, $to);
            }

            $appointment->forceFill(['status' => $to])->save();

            $this->recordStatus($appointment, $from, $to, $actor, $note);

            return $appointment;
        });
    }

    /**
     * Fail-closed validation of a bookable appointment state.
     */
    private function guard(Appointment $appointment): void
    {
        if ($appointment->starts_at === null || $appointment->ends_at === null) {
            throw AppointmentConflictException::invalidTimeRange();
        }

        if ($appointment->ends_at->lessThanOrEqualTo($appointment->starts_at)) {
            throw AppointmentConflictException::invalidTimeRange();
        }

        if (blank($appointment->patient_id) || ! Patient::query()->whereKey($appointment->patient_id)->exists()) {
            throw AppointmentConflictException::unknownPatient();
        }

        $branch = blank($appointment->branch_id)
            ? null
            : TenantBranch::query()->whereKey($appointment->branch_id)->first();

        if ($branch === null) {
            throw AppointmentConflictException::unknownBranch();
        }

        // Serialise simultaneous reservations on the shared parent rows BEFORE
        // the overlap / capacity checks, so a racing insert can never slip past
        // them. Locks are held until the surrounding tenant transaction commits.
        $this->lockSharedRows($branch, $appointment);

        if (in_array($appointment->status, Appointment::OCCUPYING_STATUSES, true)) {
            $this->assertBranchOpen($branch, $appointment);
            $this->assertBranchCapacity($branch, $appointment);
        }

        if (filled($appointment->staff_user_id)) {
            $this->assertStaffEligible((string) $appointment->staff_user_id);
            $this->assertPractitionerEligible((string) $appointment->staff_user_id, (string) $branch->getKey());

            if (in_array($appointment->status, Appointment::OCCUPYING_STATUSES, true)) {
                $this->assertNoOverlap($appointment);
            }
        }
    }

    /**
     * `SELECT … FOR UPDATE` the branch (capacity) and, when the assignee is a
     * registered practitioner, the practitioner (per-practitioner
     * double-booking). Harmless no-op on SQLite; on MySQL it serialises racing
     * reservations inside the transaction.
     */
    private function lockSharedRows(TenantBranch $branch, Appointment $appointment): void
    {
        TenantBranch::query()->whereKey($branch->getKey())->lockForUpdate()->first();

        if (filled($appointment->staff_user_id)) {
            Practitioner::query()
                ->where('user_id', $appointment->staff_user_id)
                ->lockForUpdate()
                ->first();
        }
    }

    /**
     * When the assignee is a REGISTERED practitioner (WS7), the booking must
     * also satisfy the practitioner booking gate: verified, active, still a
     * clinical member, and authorized for the branch. A staff member who is not
     * a registered practitioner keeps the historical membership-only behaviour,
     * so legacy bookings remain valid.
     */
    private function assertPractitionerEligible(string $staffUserId, string $branchId): void
    {
        $practitioner = Practitioner::query()->where('user_id', $staffUserId)->first();

        if ($practitioner === null) {
            return;
        }

        $registry = app(PractitionerRegistry::class);

        if (! $registry->isEligibleForBooking($practitioner)) {
            throw AppointmentConflictException::staffNotEligible();
        }

        if (! $registry->isAuthorizedForBranch($practitioner, $branchId)) {
            throw AppointmentConflictException::practitionerUnavailable();
        }
    }

    /**
     * A temporary / event site may only be booked inside its declared window.
     */
    private function assertBranchOpen(TenantBranch $branch, Appointment $appointment): void
    {
        if (! $branch->isBookableOn($appointment->starts_at)) {
            throw AppointmentConflictException::branchNotOpen();
        }
    }

    /**
     * Enforce a branch-level capacity when one is configured. A branch with no
     * capacity is unlimited (the historical behaviour). Overlapping occupying
     * appointments at the same location count towards the cap.
     */
    private function assertBranchCapacity(TenantBranch $branch, Appointment $appointment): void
    {
        $capacity = $branch->effectiveCapacity();

        if ($capacity === null) {
            return;
        }

        $overlapping = Appointment::query()
            ->where('branch_id', $branch->getKey())
            ->whereIn('status', Appointment::OCCUPYING_STATUSES)
            ->where('starts_at', '<', $appointment->ends_at)
            ->where('ends_at', '>', $appointment->starts_at)
            ->when(
                $appointment->exists,
                fn ($query) => $query->whereKeyNot($appointment->getKey()),
            )
            ->count();

        if ($overlapping >= $capacity) {
            throw AppointmentConflictException::branchAtCapacity();
        }
    }

    /**
     * The assigned practitioner must be an active member of the current tenant.
     */
    private function assertStaffEligible(string $staffUserId): void
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null) {
            throw AppointmentConflictException::missingContext();
        }

        $eligible = TenantMembership::query()
            ->where('user_id', $staffUserId)
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->exists();

        if (! $eligible) {
            throw AppointmentConflictException::staffNotEligible();
        }
    }

    /**
     * Reject an obvious double booking of the SAME practitioner at overlapping
     * times. Cancelled / no-show appointments no longer occupy a slot.
     */
    private function assertNoOverlap(Appointment $appointment): void
    {
        $overlaps = Appointment::query()
            ->where('staff_user_id', $appointment->staff_user_id)
            ->whereIn('status', Appointment::OCCUPYING_STATUSES)
            ->where('starts_at', '<', $appointment->ends_at)
            ->where('ends_at', '>', $appointment->starts_at)
            ->when(
                $appointment->exists,
                fn ($query) => $query->whereKeyNot($appointment->getKey()),
            )
            ->exists();

        if ($overlaps) {
            throw AppointmentConflictException::doubleBooked();
        }
    }

    private function recordStatus(
        Appointment $appointment,
        ?string $from,
        string $to,
        ?User $actor = null,
        ?string $note = null,
    ): void {
        AppointmentStatusHistory::query()->create([
            'appointment_id' => $appointment->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor?->getKey(),
            'note' => $note,
            'changed_at' => now(),
        ]);
    }
}
