<?php

namespace Modules\Consultations\Services;

use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationAuditLog;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Models\ConsultationStatusHistory;
use Modules\Patients\Models\Patient;
use Modules\Practitioners\Services\PractitionerAssignment;

/**
 * Workstream 2 — consultation creation, practitioner scheduling and lifecycle.
 *
 * Every mutation runs inside a transaction on the verified tenant database and
 * re-validates against it:
 *  - the patient and branch must exist in the CURRENT tenant database (a foreign
 *    id can never resolve, so a cross-tenant consultation is impossible);
 *  - the linked appointment, when set, must exist in the current tenant;
 *  - the assigned practitioner, when set, must be an ACTIVE member of the
 *    current tenant (central registry) and, when they are a REGISTERED
 *    practitioner, must satisfy the WS7 practitioner booking gate (verified,
 *    active, and authorized for the consultation's branch). A staff member who
 *    is not on the roster keeps the historical membership-only rule.
 *
 * Every status change is appended to the consultation's status history and a
 * compact audit entry is written, so the encounter is auditable.
 */
final class ConsultationManager
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly PractitionerAssignment $assignment,
        private readonly AppointmentScheduler $appointments,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Consultation
    {
        return $this->tenantTransaction(function () use ($attributes, $actor): Consultation {
            $consultation = new Consultation;
            $consultation->fill($attributes);

            if (! in_array($consultation->status, Consultation::STATUSES, true)) {
                $consultation->status = Consultation::STATUS_SCHEDULED;
            }

            if (! in_array($consultation->channel, Consultation::CHANNELS, true)) {
                $consultation->channel = Consultation::CHANNEL_SINA_CHAT;
            }

            if (! in_array($consultation->type, Consultation::TYPES, true)) {
                $consultation->type = Consultation::TYPE_CONSULTATION;
            }

            $consultation->created_by = $actor?->getKey();

            $this->guard($consultation);

            $consultation->save();

            $this->recordStatus($consultation, null, (string) $consultation->status, $actor);
            $this->recordAudit($consultation, ConsultationAuditLog::ACTION_CREATED, $actor, [
                'status' => $consultation->status,
                'channel' => $consultation->channel,
                'type' => $consultation->type,
            ]);

            return $consultation;
        });
    }

    /**
     * Schedule / reschedule a consultation: branch, practitioner and time.
     *
     * The status is deliberately NOT editable here: the lifecycle only moves
     * through {@see self::transition()}, which validates each transition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function schedule(Consultation $consultation, array $attributes, ?User $actor = null): Consultation
    {
        return $this->tenantTransaction(function () use ($consultation, $attributes, $actor): Consultation {
            $consultation->fill($attributes);

            $this->guard($consultation);

            $consultation->save();

            $this->recordAudit($consultation, ConsultationAuditLog::ACTION_SCHEDULED, $actor, [
                'staff_user_id' => $consultation->staff_user_id,
                'scheduled_at' => $consultation->scheduled_at?->toIso8601String(),
                'branch_id' => $consultation->branch_id,
            ]);

            return $consultation;
        });
    }

    /**
     * WS7.C — hand a CHECKED-IN appointment into an authorized consultation.
     *
     * Uses the existing `appointment_id` foreign key and is idempotent: a
     * consultation already linked to the appointment is returned as-is, so an
     * appointment is never handed in twice and no duplicate case is created. The
     * assigned practitioner is inherited from the appointment but re-derived
     * server-side by {@see self::guard()}.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function intakeFromAppointment(Appointment $appointment, ?User $actor = null, array $attributes = []): Consultation
    {
        return $this->tenantTransaction(function () use ($appointment, $actor, $attributes): Consultation {
            $existing = Consultation::query()->where('appointment_id', $appointment->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }

            // Re-resolve the appointment on the VERIFIED tenant connection so a
            // foreign id can never resolve.
            $source = Appointment::query()->whereKey($appointment->getKey())->first();

            if ($source === null) {
                throw ConsultationException::unknownAppointment();
            }

            if ((string) $source->status !== Appointment::STATUS_CHECKED_IN) {
                throw ConsultationException::appointmentNotCheckedIn();
            }

            $consultation = new Consultation;
            $consultation->fill(array_merge([
                'patient_id' => $source->patient_id,
                'branch_id' => $source->branch_id,
                'staff_user_id' => $source->staff_user_id,
                'appointment_id' => $source->getKey(),
                'scheduled_at' => $source->starts_at,
            ], $attributes));

            if (! in_array($consultation->status, Consultation::STATUSES, true)) {
                $consultation->status = Consultation::STATUS_SCHEDULED;
            }

            if (! in_array($consultation->channel, Consultation::CHANNELS, true)) {
                $consultation->channel = Consultation::CHANNEL_IN_PERSON;
            }

            if (! in_array($consultation->type, Consultation::TYPES, true)) {
                $consultation->type = Consultation::TYPE_CONSULTATION;
            }

            $consultation->created_by = $actor?->getKey();

            $this->guard($consultation);

            $consultation->save();

            $this->recordStatus($consultation, null, (string) $consultation->status, $actor);
            $this->recordAudit($consultation, ConsultationAuditLog::ACTION_CREATED, $actor, [
                'status' => $consultation->status,
                'appointment_id' => $source->getKey(),
                'source' => 'appointment_intake',
            ]);

            return $consultation;
        });
    }

    public function transition(Consultation $consultation, string $to, ?User $actor = null, ?string $note = null): Consultation
    {
        return $this->tenantTransaction(function () use ($consultation, $to, $actor, $note): Consultation {
            $from = (string) $consultation->status;

            if (! $consultation->canTransitionTo($to)) {
                throw ConsultationException::invalidTransition($from, $to);
            }

            $attributes = ['status' => $to];

            if ($to === Consultation::STATUS_IN_PROGRESS && $consultation->started_at === null) {
                $attributes['started_at'] = now();
            }

            if (in_array($to, Consultation::TERMINAL_STATUSES, true) && $consultation->ended_at === null) {
                $attributes['ended_at'] = now();
            }

            $consultation->forceFill($attributes)->save();

            // A cancelled consultation releases the slot it reserved: the linked
            // appointment is cancelled through the same scheduler the clinic uses,
            // so the practitioner's slot becomes bookable again (a checked-in or
            // completed appointment is terminal and is left untouched).
            if ($to === Consultation::STATUS_CANCELLED) {
                $this->releaseLinkedAppointment($consultation, $actor, $note);
            }

            $this->recordStatus($consultation, $from, $to, $actor, $note);
            $this->recordAudit($consultation, ConsultationAuditLog::ACTION_STATUS_CHANGED, $actor, [
                'from' => $from,
                'to' => $to,
            ]);

            return $consultation;
        });
    }

    /**
     * Append a message to the consultation transcript.
     *
     * @param  array<string, mixed>  $extra
     */
    public function appendMessage(
        Consultation $consultation,
        string $role,
        string $content,
        array $extra = [],
        ?User $actor = null,
    ): ConsultationMessage {
        return $this->tenantTransaction(function () use ($consultation, $role, $content, $extra, $actor): ConsultationMessage {
            return ConsultationMessage::query()->create(array_merge([
                'consultation_id' => $consultation->getKey(),
                'role' => $role,
                'content' => $content,
                'created_by' => $actor?->getKey(),
            ], $extra));
        });
    }

    /**
     * Fail-closed validation of a schedulable consultation state.
     */
    private function guard(Consultation $consultation): void
    {
        if (blank($consultation->patient_id) || ! Patient::query()->whereKey($consultation->patient_id)->exists()) {
            throw ConsultationException::unknownPatient();
        }

        if (blank($consultation->branch_id) || ! TenantBranch::query()->whereKey($consultation->branch_id)->exists()) {
            throw ConsultationException::unknownBranch();
        }

        if (filled($consultation->appointment_id)
            && ! Appointment::query()->whereKey($consultation->appointment_id)->exists()) {
            throw ConsultationException::unknownAppointment();
        }

        if (filled($consultation->staff_user_id)) {
            $this->assertStaffEligible(
                (string) $consultation->staff_user_id,
                $consultation->branch_id === null ? null : (string) $consultation->branch_id,
            );
        }
    }

    /**
     * The assigned practitioner must be an active member of the current tenant
     * and, when they are a REGISTERED practitioner, must also satisfy the WS7
     * booking gate for the consultation's branch (verified, active and
     * branch-authorized). A staff member who is not on the roster keeps the
     * historical membership-only rule.
     */
    private function assertStaffEligible(string $staffUserId, ?string $branchId): void
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null) {
            throw ConsultationException::missingContext();
        }

        $eligible = TenantMembership::query()
            ->where('user_id', $staffUserId)
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->exists();

        if (! $eligible) {
            throw ConsultationException::staffNotEligible();
        }

        if (! $this->assignment->allowsAssignment($staffUserId, $branchId)) {
            throw ConsultationException::staffNotEligible();
        }
    }

    /**
     * Cancel the appointment a consultation was booked against so its slot is
     * released. The appointment is re-resolved on the VERIFIED tenant
     * connection (a foreign id can never resolve) and only a status that still
     * occupies the slot is cancelled; a terminal appointment is left untouched.
     */
    private function releaseLinkedAppointment(Consultation $consultation, ?User $actor, ?string $note): void
    {
        if (blank($consultation->appointment_id)) {
            return;
        }

        $appointment = Appointment::query()->whereKey($consultation->appointment_id)->first();

        if ($appointment === null || ! $appointment->canTransitionTo(Appointment::STATUS_CANCELLED)) {
            return;
        }

        $this->appointments->transition($appointment, Appointment::STATUS_CANCELLED, $actor, $note);
    }

    private function recordStatus(
        Consultation $consultation,
        ?string $from,
        string $to,
        ?User $actor = null,
        ?string $note = null,
    ): void {
        ConsultationStatusHistory::query()->create([
            'consultation_id' => $consultation->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor?->getKey(),
            'note' => $note,
            'changed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function recordAudit(Consultation $consultation, string $action, ?User $actor, array $changes = []): void
    {
        ConsultationAuditLog::query()->create([
            'consultation_id' => $consultation->getKey(),
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'changes' => $changes,
            'occurred_at' => now(),
        ]);
    }
}
