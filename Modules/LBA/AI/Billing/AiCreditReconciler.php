<?php

namespace Modules\LBA\AI\Billing;

use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Models\TenantAiWalletLedgerEntry;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Support\Carbon;
use Modules\LBA\Models\AnalysisJob;

/**
 * Phase 6 — repairs stranded reservations and unsettled completed jobs.
 *
 * The tenant database (AI jobs) and the central database (wallet + ledger)
 * cannot share an atomic transaction, so a crash between the two can leave:
 *  - a job whose credits_state is still `reserved` although the ledger already
 *    captured or released (the tenant flag just needs repair), or
 *  - a completed job whose reservation was never captured, or a failed/stale
 *    job whose reservation was never released, or
 *  - an orphan reservation whose tenant job row never survived (the tenant
 *    transaction rolled back after the central reservation committed).
 *
 * The CENTRAL LEDGER is the source of truth: reconciliation only ever settles a
 * reservation exactly once (every operation is idempotent), so re-running it is
 * always safe and can never double-charge or double-refund.
 */
final class AiCreditReconciler
{
    public function __construct(
        private readonly ResolvesTenantDatabase $databases,
        private readonly AiWalletService $wallets,
        private readonly WalletAiCreditReserver $reserver,
    ) {}

    /**
     * @return array{captured: int, released: int, repaired: int, orphans: int}
     */
    public function reconcileTenant(string $tenantId, bool $dryRun = false): array
    {
        $this->databases->configure($tenantId);

        try {
            $cutoff = now()->subMinutes((int) config('lba.ai.billing.reconcile_after_minutes', 15));
            $summary = ['captured' => 0, 'released' => 0, 'repaired' => 0, 'orphans' => 0];

            $jobs = AnalysisJob::query()
                ->where('billable', true)
                ->where('credits_state', AnalysisJob::CREDITS_RESERVED)
                ->get();

            foreach ($jobs as $job) {
                $entries = $this->wallets->ledgerEntriesForJob($tenantId, (string) $job->request_ref);

                if ($entries->contains(fn (TenantAiWalletLedgerEntry $e): bool => $e->action === TenantAiWalletLedgerEntry::ACTION_CAPTURE)) {
                    $this->repair($job, AnalysisJob::CREDITS_CAPTURED, $dryRun);
                    $summary['repaired']++;

                    continue;
                }

                if ($entries->contains(fn (TenantAiWalletLedgerEntry $e): bool => $e->action === TenantAiWalletLedgerEntry::ACTION_RELEASE)) {
                    $this->repair($job, AnalysisJob::CREDITS_RELEASED, $dryRun);
                    $summary['repaired']++;

                    continue;
                }

                // Nothing settled yet: a completed job must be captured, a
                // definitively finished (failed/cancelled) or stale job released.
                if ($job->status === AnalysisJob::STATUS_COMPLETED) {
                    if (! $dryRun) {
                        $this->reserver->capture($job);
                    }

                    $summary['captured']++;

                    continue;
                }

                $finished = in_array($job->status, [AnalysisJob::STATUS_FAILED, AnalysisJob::STATUS_CANCELLED], true);
                $stale = $job->created_at !== null && $job->created_at->lt($cutoff);

                if ($finished || $stale) {
                    if (! $dryRun) {
                        $this->reserver->release($job);
                    }

                    $summary['released']++;
                }
            }

            $summary['orphans'] = $this->reconcileOrphans($tenantId, $cutoff, $dryRun);

            return $summary;
        } finally {
            $this->databases->forget();
        }
    }

    /**
     * Refund reservations in the ledger whose tenant job row no longer exists.
     */
    private function reconcileOrphans(string $tenantId, Carbon $cutoff, bool $dryRun): int
    {
        $reservations = TenantAiWalletLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('action', TenantAiWalletLedgerEntry::ACTION_RESERVE)
            ->where('created_at', '<', $cutoff)
            ->get();

        $refunded = 0;

        foreach ($reservations as $entry) {
            $reference = (string) $entry->job_reference;

            if ($reference === '' || $entry->credit_units <= 0) {
                continue;
            }

            $settled = TenantAiWalletLedgerEntry::query()
                ->where('tenant_id', $tenantId)
                ->where('job_reference', $reference)
                ->whereIn('action', [
                    TenantAiWalletLedgerEntry::ACTION_CAPTURE,
                    TenantAiWalletLedgerEntry::ACTION_RELEASE,
                ])
                ->exists();

            if ($settled) {
                continue;
            }

            // A still-existing job is handled by the job pass above.
            if (AnalysisJob::query()->where('request_ref', $reference)->exists()) {
                continue;
            }

            if (! $dryRun) {
                $this->wallets->release(
                    tenantId: $tenantId,
                    jobReference: $reference,
                    units: (int) $entry->credit_units,
                    idempotencyKey: 'lba-ai:release:'.$reference.':orphan',
                );
            }

            $refunded++;
        }

        return $refunded;
    }

    private function repair(AnalysisJob $job, string $state, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $job->forceFill(['credits_state' => $state])->save();
    }
}
