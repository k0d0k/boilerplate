<?php

namespace Modules\LBA\Exceptions;

use RuntimeException;

/**
 * Phase 4 — a rejected LBA examination / image / annotation operation.
 *
 * Every factory returns a safe, user-presentable message. The exception is the
 * single failure type of the LBA services; callers translate it into a 403/422
 * or a Filament validation error rather than leaking internals.
 */
final class ExaminationException extends RuntimeException
{
    public static function unknownPatient(): self
    {
        return new self('The selected patient does not belong to this partner.');
    }

    public static function unknownBranch(): self
    {
        return new self('The selected branch does not belong to this partner.');
    }

    public static function appointmentMismatch(): self
    {
        return new self('The selected appointment does not belong to this patient.');
    }

    public static function appointmentNotCheckedIn(): self
    {
        return new self('Only a checked-in appointment can be handed into an LBA examination.');
    }

    public static function operatorNotEligible(): self
    {
        return new self('The selected operator is not an active, verified practitioner authorized for this branch.');
    }

    public static function consentRequired(): self
    {
        return new self('A recorded examination image-processing consent is required before images can be processed.');
    }

    public static function consentInvalid(): self
    {
        return new self('The selected consent is not a granted examination image-processing consent.');
    }

    public static function notEditable(string $status): self
    {
        return new self("An examination in status [{$status}] can no longer be edited.");
    }

    public static function modeNotProcessable(string $mode): self
    {
        return new self("Examination mode [{$mode}] is not yet available for processing.");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("An examination cannot move from [{$from}] to [{$to}].");
    }

    public static function imageNotOnExamination(): self
    {
        return new self('The selected image does not belong to this examination.');
    }

    public static function imageDeletionDisabled(): self
    {
        return new self('Original microscopy images are retained and cannot be deleted until an approved retention and archive policy exists.');
    }

    public static function missingContext(): self
    {
        return new self('No verified partner context is available for this operation.');
    }

    public static function invalidFile(string $reason): self
    {
        return new self("The uploaded file was rejected: {$reason}");
    }

    public static function tooManyImages(int $limit): self
    {
        return new self("No more than {$limit} images may be uploaded at once.");
    }

    public static function invalidAnnotation(string $reason): self
    {
        return new self("The annotation was rejected: {$reason}");
    }

    public static function ingestNotAuthorized(): self
    {
        return new self('The operator is not authorized to issue an ingestion credential for this examination.');
    }
}
