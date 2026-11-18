<?php

namespace Modules\Practitioners\Exceptions;

use RuntimeException;

/**
 * WS7.A — a refused practitioner-registry operation.
 *
 * Every factory returns a safe, user-presentable message; internals (another
 * tenant's id, a raw licence number, another identity) are never leaked.
 * Callers translate it into a Filament notification or a form error rather than
 * a 500.
 */
final class PractitionerException extends RuntimeException
{
    public static function notAuthorized(): self
    {
        return new self('You are not authorised to manage practitioner records in the verified clinic context.');
    }

    public static function notAReviewer(): self
    {
        return new self('You are not authorised to verify or suspend practitioner credentials.');
    }

    public static function missingContext(): self
    {
        return new self('No verified clinic context is available for this operation.');
    }

    public static function userNotEligible(): self
    {
        return new self('The selected user is not an active clinical member of this partner.');
    }

    public static function alreadyRegistered(): self
    {
        return new self('This user is already registered as a practitioner for this partner.');
    }

    public static function selfVerificationNotAllowed(): self
    {
        return new self('A practitioner cannot verify or suspend their own credential (separation of duties).');
    }

    public static function notPending(): self
    {
        return new self('Only a pending practitioner can be verified.');
    }

    public static function unknownPractitioner(): self
    {
        return new self('The practitioner record could not be found in the current partner.');
    }

    public static function unknownCredential(): self
    {
        return new self('The credential record could not be found for this practitioner.');
    }

    public static function credentialNotPending(): self
    {
        return new self('Only a pending credential can be verified or rejected.');
    }

    public static function scheduleNotManageable(): self
    {
        return new self('You are not authorised to manage practice schedules in the verified clinic context.');
    }

    public static function practitionerNotEligible(): self
    {
        return new self('Only a verified, active practitioner of this partner can be scheduled.');
    }

    public static function branchNotAuthorized(): self
    {
        return new self('The practitioner is not authorised to practise at this branch.');
    }

    public static function invalidTimeRange(): self
    {
        return new self('The end time must be after the start time.');
    }

    public static function invalidWeekday(): self
    {
        return new self('The weekday must be a value between 1 (Monday) and 7 (Sunday).');
    }

    public static function invalidEffectiveRange(): self
    {
        return new self('The effective-from date must not be after the effective-until date.');
    }

    public static function overlappingWindow(): self
    {
        return new self('This window overlaps another window already defined for the practitioner on the same weekday.');
    }

    public static function invalidExceptionType(): self
    {
        return new self('The selected schedule exception type is not supported.');
    }

    public static function unknownException(): self
    {
        return new self('The schedule exception could not be found for this practitioner.');
    }
}
