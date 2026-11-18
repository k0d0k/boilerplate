<?php

namespace Modules\LBA\AI\Billing;

/**
 * Phase 6 — the immutable billability decision for one AI job.
 *
 * Produced ONCE when a job is first queued, then persisted on the job row. The
 * stored `unitPriceCredits` is the price SNAPSHOT: capture and release always
 * use it, so a later pricing change never re-prices a job that already reserved
 * credits.
 */
final readonly class AiPriceSnapshot
{
    public function __construct(
        public bool $billable,
        public ?string $ruleReference = null,
        public int $unitPriceCredits = 0,
    ) {}

    public static function notBillable(): self
    {
        return new self(false);
    }
}
