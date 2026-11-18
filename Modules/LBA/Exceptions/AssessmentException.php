<?php

namespace Modules\LBA\Exceptions;

use RuntimeException;

/**
 * Phase 7A — a rejected LBA observation-assessment operation.
 *
 * Every factory returns a safe, user-presentable message. The exception is the
 * single failure type of the assessment services; callers translate it into a
 * Filament validation error or a 403 rather than leaking internals.
 */
final class AssessmentException extends RuntimeException
{
    public static function examinationNotAssessable(string $status): self
    {
        return new self("An examination in status [{$status}] cannot be assessed yet.");
    }

    public static function notEditable(string $status): self
    {
        return new self("An assessment in status [{$status}] can no longer be edited.");
    }

    public static function locked(): self
    {
        return new self('This assessment has been submitted and is locked; it can no longer be overwritten.');
    }

    public static function unknownIndicator(string $key): self
    {
        return new self("The indicator [{$key}] is not part of this assessment.");
    }

    public static function invalidCategory(string $category): self
    {
        return new self("The category [{$category}] is not a valid assessment option.");
    }

    public static function invalidProgramPhase(string $phase): self
    {
        return new self("The program phase [{$phase}] is not valid.");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("An assessment cannot move from [{$from}] to [{$to}].");
    }

    public static function foreignEvidence(): self
    {
        return new self('The selected evidence does not belong to this examination.');
    }

    public static function invalidEvidenceType(string $type): self
    {
        return new self("The evidence type [{$type}] is not supported.");
    }

    public static function brandNotAvailable(): self
    {
        return new self('The selected brand is not an active brand linked to this partner.');
    }

    public static function missingContext(): self
    {
        return new self('No verified partner context is available for this operation.');
    }
}
