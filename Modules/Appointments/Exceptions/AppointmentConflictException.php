<?php

namespace Modules\Appointments\Exceptions;

use RuntimeException;

/**
 * Thrown when an appointment would collide with another booking of the same
 * practitioner, or when an invalid status transition is attempted.
 *
 * Every factory carries a STABLE error code (alongside the human message) so a
 * caller — the patient API, the patient portal — can map the refusal to a
 * specific, safe response without string-matching the message.
 */
final class AppointmentConflictException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function doubleBooked(): self
    {
        return new self('slot_unavailable', 'The assigned practitioner already has an appointment in this time range.');
    }

    public static function branchAtCapacity(): self
    {
        return new self('branch_at_capacity', 'The selected location has no remaining capacity for this time.');
    }

    public static function branchNotOpen(): self
    {
        return new self('branch_not_open', 'The selected location is not open for the requested time.');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self('invalid_transition', "An appointment cannot move from [{$from}] to [{$to}].");
    }

    public static function staffNotEligible(): self
    {
        return new self('staff_not_eligible', 'The assigned staff member is not an active member of this partner.');
    }

    public static function practitionerUnavailable(): self
    {
        return new self('practitioner_unavailable', 'The selected practitioner is not available for the requested time.');
    }

    public static function unknownPatient(): self
    {
        return new self('unknown_patient', 'The selected patient does not belong to this partner.');
    }

    public static function unknownBranch(): self
    {
        return new self('unknown_branch', 'The selected branch does not belong to this partner.');
    }

    public static function invalidTimeRange(): self
    {
        return new self('invalid_time_range', 'The appointment end time must be after its start time.');
    }

    public static function missingContext(): self
    {
        return new self('missing_context', 'No verified partner context is active.');
    }
}
