<?php

namespace Modules\Consultations\Services;

use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Illuminate\Support\Carbon;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Patients\Models\Patient;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerAvailability;
use Modules\Practitioners\Services\PractitionerBooking;

/**
 * Workstream 2 / WS7.C — the SHARED human-consultation booking path.
 *
 * The patient portal and the patient API both funnel here, so a human session
 * is booked identically in both: the write goes through the SAME
 * {@see ConsultationManager} the clinic uses, and, when the patient names a
 * practitioner, through the SAME {@see PractitionerBooking} chokepoint the
 * appointment booking already uses.
 *
 * Choosing a practitioner is OPTIONAL and backward compatible: an empty choice
 * keeps the legacy "clinic assigns" behaviour (a consultation with no
 * practitioner). When a practitioner IS named, the slot is only confirmed when
 * EVERY gate allows it — the practitioner is verified, an active clinical
 * member and AUTHORIZED for the branch, the requested range falls inside their
 * own practice schedule and is not blocked by leave, and the branch still has
 * capacity. A forged practitioner id is refused, never silently downgraded.
 *
 * The slot reservation and the consultation are written in ONE tenant
 * transaction, so a consultation that fails to persist can never leave a slot
 * reserved (and vice versa). Authorization (WHO may book for WHOM) stays with
 * the caller — the portal forces the actor's own patient record exactly as
 * before.
 */
final class ConsultationBooking
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly ConsultationManager $manager,
        private readonly PractitionerBooking $practitionerBooking,
        private readonly PractitionerAvailability $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function book(
        TenantBranch $branch,
        array $attributes,
        ?string $practitionerId = null,
        ?User $actor = null,
    ): Consultation {
        return $this->tenantTransaction(function () use ($branch, $attributes, $practitionerId, $actor): Consultation {
            if (blank($practitionerId)) {
                return $this->manager->create($attributes, $actor);
            }

            $practitioner = Practitioner::query()->whereKey($practitionerId)->first();

            if ($practitioner === null) {
                throw ConsultationException::unknownPractitioner();
            }

            // The practitioner must be one of the BOOKABLE practitioners of the
            // branch (verified, active clinical member, branch-authorized).
            $bookable = $this->availability->bookablePractitioners($branch)
                ->contains(fn (Practitioner $candidate): bool => (string) $candidate->getKey() === (string) $practitioner->getKey());

            if (! $bookable) {
                throw ConsultationException::practitionerUnavailable();
            }

            $patient = Patient::query()->whereKey($attributes['patient_id'] ?? null)->first();

            if ($patient === null) {
                throw ConsultationException::unknownPatient();
            }

            $appointment = $this->reserveSlot(
                $practitioner,
                $branch,
                $patient,
                Carbon::parse($attributes['scheduled_at']),
                $this->serviceTypeFor($attributes['type'] ?? null),
                $attributes['reason'] ?? null,
                $actor,
            );

            return $this->manager->create(array_merge($attributes, [
                'staff_user_id' => (string) $practitioner->user_id,
                'appointment_id' => $appointment->getKey(),
            ]), $actor);
        });
    }

    /**
     * The appointment service type that mirrors a consultation type.
     */
    public function serviceTypeFor(?string $consultationType): string
    {
        return match ($consultationType) {
            Consultation::TYPE_FOLLOW_UP => Appointment::SERVICE_FOLLOW_UP,
            Consultation::TYPE_LBA_REVIEW => Appointment::SERVICE_LBA_EXAM,
            default => Appointment::SERVICE_CONSULTATION,
        };
    }

    private function reserveSlot(
        Practitioner $practitioner,
        TenantBranch $branch,
        Patient $patient,
        Carbon $startsAt,
        string $serviceType,
        ?string $notes,
        ?User $actor,
    ): Appointment {
        try {
            return $this->practitionerBooking->reserve(
                $practitioner,
                $branch,
                $patient,
                $startsAt,
                $serviceType,
                notes: $notes,
                actor: $actor,
            );
        } catch (AppointmentConflictException $exception) {
            throw $this->mapConflict($exception);
        }
    }

    private function mapConflict(AppointmentConflictException $exception): ConsultationException
    {
        return match ($exception->errorCode()) {
            'branch_at_capacity' => ConsultationException::branchAtCapacity(),
            'branch_not_open' => ConsultationException::branchNotOpen(),
            'invalid_time_range' => ConsultationException::invalidTimeRange(),
            'staff_not_eligible' => ConsultationException::staffNotEligible(),
            'practitioner_unavailable' => ConsultationException::practitionerUnavailable(),
            default => ConsultationException::slotUnavailable(),
        };
    }
}
