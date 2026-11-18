<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Illuminate\Database\QueryException;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Support\PatientNumberGenerator;

/**
 * Patient registration / update with a minimal audit trail (Phase 3).
 *
 * Every write happens inside a transaction and appends one audit entry. The
 * clinic patient number is allocated safely: a unique-constraint collision
 * (two concurrent registrations) is retried with a freshly generated number
 * rather than surfaced to the operator.
 */
final class PatientRegistrar
{
    use RunsVerifiedTenantTransactions;

    public function __construct(private readonly PatientNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Patient
    {
        return $this->tenantTransaction(function () use ($attributes, $actor): Patient {
            $patient = $this->persistWithUniqueNumber(null, $attributes, $actor);

            $this->audit($patient, PatientAuditLog::ACTION_CREATED, $actor, $attributes);

            return $patient;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Patient $patient, array $attributes, ?User $actor = null): Patient
    {
        return $this->tenantTransaction(function () use ($patient, $attributes, $actor): Patient {
            $patient->fill($attributes)->save();

            $this->audit($patient, PatientAuditLog::ACTION_UPDATED, $actor, $attributes);

            return $patient;
        });
    }

    public function archive(Patient $patient, ?User $actor = null): void
    {
        $this->tenantTransaction(function () use ($patient, $actor): void {
            $patient->archive();

            $this->audit($patient, PatientAuditLog::ACTION_ARCHIVED, $actor);
        });
    }

    public function restore(Patient $patient, ?User $actor = null): void
    {
        $this->tenantTransaction(function () use ($patient, $actor): void {
            $patient->restore();

            $this->audit($patient, PatientAuditLog::ACTION_RESTORED, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistWithUniqueNumber(?Patient $patient, array $attributes, ?User $actor): Patient
    {
        $attempts = 0;

        while (true) {
            $patient ??= new Patient;

            $patient->fill($attributes);

            if ($patient->patient_number === null || $patient->patient_number === '') {
                $patient->patient_number = $this->numbers->next();
            }

            try {
                $patient->save();

                return $patient;
            } catch (QueryException $exception) {
                // Only a patient-number collision is retried; any other integrity
                // error is re-thrown untouched.
                if ($attempts++ >= 5 || ! $this->isNumberCollision($exception)) {
                    throw $exception;
                }

                $patient->patient_number = $this->numbers->next();
            }
        }
    }

    private function isNumberCollision(QueryException $exception): bool
    {
        return str_contains((string) $exception->getMessage(), 'patient_number');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(Patient $patient, string $action, ?User $actor, array $changes = []): void
    {
        PatientAuditLog::query()->create([
            'patient_id' => $patient->getKey(),
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'changes' => $changes === [] ? null : $changes,
            'occurred_at' => now(),
        ]);
    }
}
