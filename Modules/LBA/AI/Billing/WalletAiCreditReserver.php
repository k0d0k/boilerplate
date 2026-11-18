<?php

namespace Modules\LBA\AI\Billing;

use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Exceptions\AiBillingException;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Modules\LBA\AI\Contracts\ReservesAiCredits;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\Models\AnalysisJob;

/**
 * Phase 6 — the real, tenant-scoped AI credit reserver.
 *
 * Replaces the Phase 5 no-op. Every operation is idempotent through a
 * deterministic, per-job/action/per-attempt idempotency key, so a repeated
 * callback, a retry or a reconciliation can never double-charge or
 * double-refund.
 *
 * The wallet and ledger live in the CENTRAL database; the job's durable billing
 * state lives on the tenant-owned job row. This class is the ONLY seam that
 * bridges the two, and it always drives the central ledger FIRST so the ledger
 * remains the source of truth that reconciliation repairs the job row from.
 *
 * Nothing here reads or writes patient data — only the opaque job reference and
 * integer accounting.
 */
final class WalletAiCreditReserver implements ReservesAiCredits
{
    public function __construct(
        private readonly ResolvesTenantDatabase $databases,
        private readonly AiWalletService $wallets,
        private readonly AiBillingPolicy $policy,
    ) {}

    public function reserve(AnalysisJob $job): void
    {
        $tenantId = $this->databases->current();

        if ($tenantId === null) {
            // No verified tenant context: nothing can be billed.
            return;
        }

        try {
            $snapshot = $this->policy->decide($job);
        } catch (AiBillingException $exception) {
            throw $this->translate($exception);
        }

        if (! $snapshot->billable) {
            $job->forceFill([
                'billable' => false,
                'pricing_rule_ref' => null,
                'unit_price_credits' => null,
                'credits_state' => null,
            ])->save();

            return;
        }

        try {
            $this->wallets->reserve(
                tenantId: $tenantId,
                jobReference: (string) $job->request_ref,
                units: $snapshot->unitPriceCredits,
                idempotencyKey: $this->key('reserve', $job),
                actorReference: $job->requested_by,
            );
        } catch (AiBillingException $exception) {
            throw $this->translate($exception);
        }

        $job->forceFill([
            'billable' => true,
            'pricing_rule_ref' => $snapshot->ruleReference,
            'unit_price_credits' => $snapshot->unitPriceCredits,
            'credits_state' => AnalysisJob::CREDITS_RESERVED,
        ])->save();
    }

    public function capture(AnalysisJob $job): void
    {
        if (! $job->isBillable() || ! $job->creditsReserved()) {
            return;
        }

        $tenantId = $this->databases->current();
        $units = $job->creditUnits();

        if ($tenantId === null || $units <= 0) {
            return;
        }

        // Central ledger first: the capture is durable even if the tenant flag
        // update below is interrupted, and reconciliation settles the flag.
        $this->wallets->capture(
            tenantId: $tenantId,
            jobReference: (string) $job->request_ref,
            units: $units,
            idempotencyKey: $this->key('capture', $job),
            usage: [
                'provider' => (string) $job->provider,
                'model_name' => $job->model_name,
                'model_version' => $job->model_version,
                'pricing_rule_ref' => $job->pricing_rule_ref,
                'unit_price_credits' => $units,
            ],
            actorReference: $job->requested_by,
        );

        $job->forceFill(['credits_state' => AnalysisJob::CREDITS_CAPTURED])->save();
    }

    public function release(AnalysisJob $job): void
    {
        if (! $job->isBillable() || ! $job->creditsReserved()) {
            return;
        }

        $tenantId = $this->databases->current();
        $units = $job->creditUnits();

        if ($tenantId === null || $units <= 0) {
            return;
        }

        $this->wallets->release(
            tenantId: $tenantId,
            jobReference: (string) $job->request_ref,
            units: $units,
            idempotencyKey: $this->key('release', $job),
            actorReference: $job->requested_by,
        );

        $job->forceFill(['credits_state' => AnalysisJob::CREDITS_RELEASED])->save();
    }

    /**
     * A deterministic idempotency key. Reserve/release are per ATTEMPT (so a
     * retry after a release re-reserves safely); capture is per JOB (so a job is
     * billed at most once, whatever the attempt count).
     */
    private function key(string $action, AnalysisJob $job): string
    {
        $scoped = $action === 'capture'
            ? (string) $job->request_ref
            : (string) $job->request_ref.':'.(int) $job->attempts;

        return 'lba-ai:'.$action.':'.$scoped;
    }

    /**
     * Translate a central billing rejection into an operator-facing AI error.
     */
    private function translate(AiBillingException $exception): AiAnalysisException
    {
        return match ($exception->errorCode()) {
            'insufficient_credits' => AiAnalysisException::insufficientCredits(),
            'pricing_unavailable' => AiAnalysisException::pricingUnavailable(),
            default => AiAnalysisException::walletUnavailable(),
        };
    }
}
