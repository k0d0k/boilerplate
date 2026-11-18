<?php

namespace Modules\Patients\Exceptions;

use RuntimeException;

/**
 * Phase 8A — a refused patient portal account-link operation.
 *
 * Every factory returns a safe, user-presentable message; internals (a token,
 * another account id, another tenant) are never leaked. Callers translate it
 * into a Filament notification or a form error rather than a 500.
 */
final class PatientLinkException extends RuntimeException
{
    public static function notAuthorized(): self
    {
        return new self('You are not authorised to manage patient portal accounts in the verified clinic context.');
    }

    public static function invitationInvalid(): self
    {
        return new self('The portal invitation is invalid or has expired.');
    }

    public static function alreadyLinked(): self
    {
        return new self('This patient record is already linked to a different account.');
    }
}
