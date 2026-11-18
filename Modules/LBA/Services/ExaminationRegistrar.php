<?php

namespace Modules\LBA\Services;

use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Modules\Appointments\Models\Appointment;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Models\Examination;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Models\PatientConsent;
use Modules\Practitioners\Services\PractitionerAssignment;

/**
 * Phase 4 — examination create / draft update (Modules/LBA).
 *
 * Every write happens inside a transaction against the VERIFIED tenant
 * database, so a foreign patient, branch or appointment id can never resolve
 * and a cross-tenant examination is impossible. The examination records the
 * explicit image-processing consent reference (or records a new consent on the
 * spot); it never infers consent from an unrelated grant.
 *
 * WS7.C — when the operator is a REGISTERED practitioner they must satisfy the
 * practitioner booking gate (verified, active) and be authorized for the
 * examination's branch; a staff member who is not on the roster keeps the
 * historical behaviour, so existing records are never broken.
 */
final class ExaminationRegistrar
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly ExaminationWorkflow $workflow,
        private readonly PractitionerAssignment $assignment,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Examination
    {
        return $this->tenantTransaction(function () use ($attributes, $actor): Examination {
            $patientId = (string) ($attributes['patient_id'] ?? '');
            $branchId = (string) ($attributes['branch_id'] ?? '');

            $this->assertPatient($patientId);
            $this->assertBranch($branchId);
            $this->assertAppointment($attributes['appointment_id'] ?? null, $patientId);

            $consentId = $this->resolveConsent($patientId, $attributes, $actor);

            $examination = new Examination;
            $examination->fill($this->fillableAttributes($attributes));
            $examination->consent_id = $consentId;
            $examination->created_by = $actor?->getKey();

            if (! in_array($examination->mode, Examination::MODES, true)) {
                $examination->mode = Examination::MODE_MANUAL;
            }

            $examination->status = Examination::STATUS_DRAFT;
            $examination->captured_at ??= now();

            if (blank($examination->operator_user_id)) {
                $examination->operator_user_id = $actor?->getKey();
            }

            $this->assertOperator($examination->operator_user_id, $branchId);

            $examination->save();

            $this->workflow->recordInitial($examination, $actor);

            return $examination;
        });
    }

    /**
     * WS7.C — hand a CHECKED-IN appointment into an LBA examination.
     *
     * Uses the existing `appointment_id` foreign key and is idempotent: an
     * examination already linked to the appointment is returned as-is, so no
     * duplicate case is created. The operator is inherited from the appointment
     * but re-derived server-side by {@see self::assertOperator()}.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function intakeFromAppointment(Appointment $appointment, ?User $actor = null, array $overrides = []): Examination
    {
        return $this->tenantTransaction(function () use ($appointment, $actor, $overrides): Examination {
            $existing = Examination::query()->where('appointment_id', $appointment->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }

            $source = Appointment::query()->whereKey($appointment->getKey())->first();

            if ($source === null) {
                throw ExaminationException::appointmentMismatch();
            }

            if ((string) $source->status !== Appointment::STATUS_CHECKED_IN) {
                throw ExaminationException::appointmentNotCheckedIn();
            }

            return $this->create(array_merge([
                'patient_id' => $source->patient_id,
                'branch_id' => $source->branch_id,
                'appointment_id' => $source->getKey(),
                'operator_user_id' => $source->staff_user_id,
            ], $overrides), $actor);
        });
    }

    /**
     * Update the permitted draft fields of an editable examination.
     *
     * The status is deliberately NOT editable here — the lifecycle only moves
     * through {@see ExaminationWorkflow}, which validates each transition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Examination $examination, array $attributes, ?User $actor = null): Examination
    {
        return $this->tenantTransaction(function () use ($examination, $attributes, $actor): Examination {
            if (! $examination->isEditable()) {
                throw ExaminationException::notEditable((string) $examination->status);
            }

            $patientId = (string) $examination->patient_id;

            if (array_key_exists('appointment_id', $attributes)) {
                $this->assertAppointment($attributes['appointment_id'], $patientId);
            }

            if (array_key_exists('branch_id', $attributes) && filled($attributes['branch_id'])) {
                $this->assertBranch((string) $attributes['branch_id']);
            }

            $consentId = $this->resolveConsent($patientId, $attributes, $actor, $examination);

            $examination->fill($this->fillableAttributes($attributes));

            if ($consentId !== null) {
                $examination->consent_id = $consentId;
            }

            if (array_key_exists('operator_user_id', $attributes)) {
                $this->assertOperator(
                    filled($examination->operator_user_id) ? (string) $examination->operator_user_id : null,
                    (string) $examination->branch_id,
                );
            }

            $examination->save();

            return $examination;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fillableAttributes(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'patient_id',
            'branch_id',
            'appointment_id',
            'operator_user_id',
            'mode',
            'captured_at',
            'instrument',
            'magnification',
            'calibration',
            'notes',
        ]));
    }

    private function assertPatient(string $patientId): void
    {
        if ($patientId === '' || ! Patient::query()->whereKey($patientId)->exists()) {
            throw ExaminationException::unknownPatient();
        }
    }

    private function assertBranch(string $branchId): void
    {
        if ($branchId === '' || ! TenantBranch::query()->whereKey($branchId)->exists()) {
            throw ExaminationException::unknownBranch();
        }
    }

    private function assertAppointment(mixed $appointmentId, string $patientId): void
    {
        if (blank($appointmentId)) {
            return;
        }

        $belongs = Appointment::query()
            ->whereKey($appointmentId)
            ->where('patient_id', $patientId)
            ->exists();

        if (! $belongs) {
            throw ExaminationException::appointmentMismatch();
        }
    }

    /**
     * WS7.C — the examination operator must be an ACTIVE member of the current
     * tenant and, when they are a REGISTERED practitioner, must satisfy the
     * practitioner booking gate (verified, active and authorized for the
     * examination's branch). A staff member who is not on the roster keeps the
     * historical membership-only rule, so existing records are never broken.
     *
     * A blank operator is permitted (the examination may simply be unassigned).
     */
    private function assertOperator(?string $operatorUserId, string $branchId): void
    {
        if (blank($operatorUserId)) {
            return;
        }

        $operatorUserId = (string) $operatorUserId;

        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null) {
            throw ExaminationException::missingContext();
        }

        $member = TenantMembership::query()
            ->where('user_id', $operatorUserId)
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->exists();

        if (! $member) {
            throw ExaminationException::operatorNotEligible();
        }

        if (! $this->assignment->allowsAssignment($operatorUserId, $branchId === '' ? null : $branchId)) {
            throw ExaminationException::operatorNotEligible();
        }
    }

    /**
     * Resolve the image-processing consent reference for an examination.
     *
     * `record_consent` records a NEW granted consent on the spot (never inferred
     * from an existing unrelated grant); otherwise an explicit `consent_id` must
     * resolve to a granted image-processing consent of the same patient.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolveConsent(
        string $patientId,
        array $attributes,
        ?User $actor,
        ?Examination $examination = null,
    ): ?string {
        if (filter_var($attributes['record_consent'] ?? false, FILTER_VALIDATE_BOOL)) {
            return $this->recordConsent(
                $patientId,
                (string) ($attributes['consent_version'] ?? 'v1'),
                $actor,
            );
        }

        $consentId = (string) ($attributes['consent_id'] ?? '');

        if ($consentId === '') {
            // No consent named in this request: keep an existing valid reference
            // on update, otherwise leave the examination without one.
            return null;
        }

        $valid = PatientConsent::query()
            ->whereKey($consentId)
            ->where('patient_id', $patientId)
            ->where('consent_type', PatientConsent::TYPE_EXAMINATION_IMAGE_PROCESSING)
            ->where('status', PatientConsent::STATUS_GRANTED)
            ->whereNull('withdrawn_at')
            ->exists();

        if (! $valid) {
            throw ExaminationException::consentInvalid();
        }

        return $consentId;
    }

    /**
     * Record a brand-new, explicitly granted image-processing consent.
     */
    private function recordConsent(string $patientId, string $version, ?User $actor): string
    {
        $consent = PatientConsent::query()->create([
            'patient_id' => $patientId,
            'consent_type' => PatientConsent::TYPE_EXAMINATION_IMAGE_PROCESSING,
            'version' => $version === '' ? 'v1' : $version,
            'status' => PatientConsent::STATUS_GRANTED,
            'recorded_by' => $actor?->getKey(),
            'recorded_at' => now(),
        ]);

        PatientAuditLog::query()->create([
            'patient_id' => $patientId,
            'action' => PatientAuditLog::ACTION_CONSENT_RECORDED,
            'actor_id' => $actor?->getKey(),
            'changes' => [
                'consent_id' => $consent->getKey(),
                'type' => $consent->consent_type,
                'source' => 'lba_examination',
            ],
            'occurred_at' => now(),
        ]);

        return (string) $consent->getKey();
    }
}
