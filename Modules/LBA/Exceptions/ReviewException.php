<?php

namespace Modules\LBA\Exceptions;

use RuntimeException;

/**
 * Phase 7B — a rejected human-review or report operation.
 *
 * Every factory returns a safe, user-presentable message. The exception is the
 * single failure type of the review/report services; callers translate it into
 * a Filament validation error or a 403 rather than leaking internals.
 */
final class ReviewException extends RuntimeException
{
    public static function notReviewable(string $status): self
    {
        return new self("An assessment in status [{$status}] cannot be reviewed.");
    }

    public static function selfApprovalForbidden(): self
    {
        return new self('A reviewer may not approve an examination they recorded or operate; a separate reviewer must sign off.');
    }

    public static function invalidDecision(string $decision): self
    {
        return new self("The review decision [{$decision}] is not supported.");
    }

    public static function brandRequired(): self
    {
        return new self('A report brand must be selected for this assessment before a report can be generated.');
    }

    public static function notApproved(): self
    {
        return new self('No report can be issued before a human reviewer approves the assessment.');
    }

    public static function reportMissing(): self
    {
        return new self('The requested report document is not available.');
    }

    public static function missingContext(): self
    {
        return new self('No verified partner context is available for this operation.');
    }

    public static function notAuthorized(): self
    {
        return new self('You are not authorised to perform this review action within the verified partner context.');
    }

    /**
     * The acting identity is a registered practitioner whose professional
     * standing is not current (suspended, expired, archived or de-membered), so
     * they may not exercise a qualified review decision.
     */
    public static function reviewerNotEligible(): self
    {
        return new self('The reviewer is not a currently verified, active practitioner of this partner.');
    }

    /**
     * Generation was interrupted AFTER the human approval was already recorded,
     * so the approval stands and the report may be issued again safely.
     */
    public static function reportGenerationFailed(): self
    {
        return new self('The report could not be generated. The approval is preserved — generate the report again to retry.');
    }
}
