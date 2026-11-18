<?php

namespace Modules\LBA\AI\Contracts;

use Modules\LBA\AI\Support\NullAiCreditReserver;
use Modules\LBA\Models\AnalysisJob;

/**
 * Phase 6 seam — AI credit reservation.
 *
 * Phase 5 deliberately does NOT implement credit wallets: it never fabricates,
 * reserves, deducts or releases any token. This interface exists so Phase 6 can
 * plug a real, tenant-scoped, idempotent credit wallet into the analysis
 * pipeline WITHOUT changing the pipeline itself.
 *
 * The Phase 5 binding ({@see NullAiCreditReserver}) is a
 * documented no-op: manual analysis is completely independent of it, and a
 * sandbox/mock inference is never billable.
 */
interface ReservesAiCredits
{
    /**
     * Reserve the credits required for one analysis job, idempotently, or do
     * nothing when AI is not yet billable.
     */
    public function reserve(AnalysisJob $job): void;

    /**
     * Capture the reserved credits once a job completes (idempotently).
     */
    public function capture(AnalysisJob $job): void;

    /**
     * Release a reservation when a job fails or is cancelled (idempotently).
     */
    public function release(AnalysisJob $job): void;
}
