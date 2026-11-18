<?php

namespace Modules\Patients\Support;

use Modules\Patients\Models\Patient;
use RuntimeException;

/**
 * Safe, tenant-unique clinic patient number allocation (Phase 3).
 *
 * The number is unique within the tenant's own database (the tenant boundary).
 * Allocation is deliberately collision-safe: a candidate is only returned once
 * it is verified absent, and the registrar additionally retries on a unique
 * constraint violation, so two concurrent registrations can never share a
 * number.
 */
final class PatientNumberGenerator
{
    private const PREFIX = 'P-';

    private const PAD = 6;

    private const MAX_ATTEMPTS = 100;

    public function next(): string
    {
        $base = Patient::query()->count() + 1;

        for ($offset = 0; $offset < self::MAX_ATTEMPTS; $offset++) {
            $candidate = self::PREFIX.str_pad((string) ($base + $offset), self::PAD, '0', STR_PAD_LEFT);

            if (! Patient::query()->where('patient_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique patient number.');
    }
}
