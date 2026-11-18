<?php

namespace Modules\Consultations\Exceptions;

use RuntimeException;

/**
 * Workstream 2 — a consultation lifecycle / scheduling rule was violated.
 *
 * Raised for an invalid status transition, a missing verified tenant context,
 * an unknown (tenant-local) patient / branch / appointment, an ineligible
 * practitioner or a missing patient consent. Every code is stable so callers
 * (the panel, the patient API) can map it to a safe response without leaking
 * internals.
 */
final class ConsultationException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function missingContext(): self
    {
        return new self('missing_context', 'No verified tenant context is active.');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self('invalid_transition', "A consultation in status [{$from}] cannot move to [{$to}].");
    }

    public static function unknownPatient(): self
    {
        return new self('unknown_patient', 'The selected patient does not belong to this clinic.');
    }

    public static function unknownBranch(): self
    {
        return new self('unknown_branch', 'The selected branch does not belong to this clinic.');
    }

    public static function unknownAppointment(): self
    {
        return new self('unknown_appointment', 'The linked appointment does not belong to this clinic.');
    }

    public static function staffNotEligible(): self
    {
        return new self('staff_not_eligible', 'The selected practitioner is not an active, verified member of this clinic.');
    }

    public static function appointmentNotCheckedIn(): self
    {
        return new self('appointment_not_checked_in', 'Only a checked-in appointment can be handed into a consultation.');
    }

    public static function notActive(): self
    {
        return new self('not_active', 'This consultation is no longer active.');
    }

    public static function unknownPractitioner(): self
    {
        return new self('unknown_practitioner', 'The selected practitioner does not belong to this clinic.');
    }

    public static function practitionerUnavailable(): self
    {
        return new self('practitioner_unavailable', 'The selected practitioner is not available for the requested time.');
    }

    public static function slotUnavailable(): self
    {
        return new self('slot_unavailable', 'That slot is no longer available.');
    }

    public static function branchNotOpen(): self
    {
        return new self('branch_not_open', 'The selected location is not open for the requested time.');
    }

    public static function branchAtCapacity(): self
    {
        return new self('branch_at_capacity', 'The selected location has no remaining capacity for this time.');
    }

    public static function invalidTimeRange(): self
    {
        return new self('invalid_time_range', 'The appointment end time must be after its start time.');
    }

    public static function consentRequired(): self
    {
        return new self('consent_required', 'Patient consent for AI consultation assistance is required.');
    }
}
