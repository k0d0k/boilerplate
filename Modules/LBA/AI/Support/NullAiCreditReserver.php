<?php

namespace Modules\LBA\AI\Support;

use Modules\LBA\AI\Contracts\ReservesAiCredits;
use Modules\LBA\Models\AnalysisJob;

/**
 * Phase 5/Phase 6 seam — a deliberate no-op credit reserver.
 *
 * Phase 6 implements AI credit wallets. Until then this adapter does NOTHING:
 * no credit is fabricated, reserved, deducted or released, and the mock adapter
 * is never treated as a billable production inference. It exists purely so the
 * analysis pipeline already calls a stable interface that Phase 6 can replace.
 */
final class NullAiCreditReserver implements ReservesAiCredits
{
    public function reserve(AnalysisJob $job): void
    {
        // Intentionally does nothing: AI is not billable in Phase 5.
    }

    public function capture(AnalysisJob $job): void
    {
        // Intentionally does nothing.
    }

    public function release(AnalysisJob $job): void
    {
        // Intentionally does nothing.
    }
}
