<?php

namespace Modules\LBA\AI\Billing;

use App\Platform\AiBilling\Exceptions\AiBillingException;
use App\Platform\AiBilling\Models\AiPricingRule;
use Modules\LBA\Models\AnalysisJob;

/**
 * Phase 6 — decides whether one AI job is billable and at what price.
 *
 * The decision is made ONCE when a job is first queued, then persisted on the
 * job row as an immutable price SNAPSHOT — so a later price change never
 * re-prices a job that already reserved credits.
 *
 * Billability rules (STEP 4):
 *  - billing disabled (sandbox/local/testing)          → NOT billable;
 *  - synthetic / mock provider                          → NOT billable;
 *  - a real provider with billing enabled but NO active
 *    pricing rule                                        → refused (the operator
 *                                                          must configure a price).
 *
 * A price is never invented: it always comes from an explicit, configurable
 * {@see AiPricingRule}. No floating-point token math is used anywhere.
 */
final class AiBillingPolicy
{
    /** Providers that perform no real inference and are never billable. */
    public const SYNTHETIC_PROVIDERS = ['mock', 'synthetic'];

    public function billingEnabled(): bool
    {
        return (bool) config('lba.ai.billing.enabled', false);
    }

    public function isSyntheticProvider(?string $provider): bool
    {
        return in_array((string) $provider, self::SYNTHETIC_PROVIDERS, true);
    }

    /**
     * Resolve the immutable price snapshot for a job.
     *
     * @throws AiBillingException when a billable provider has no approved price.
     */
    public function decide(AnalysisJob $job): AiPriceSnapshot
    {
        if (! $this->billingEnabled()) {
            return AiPriceSnapshot::notBillable();
        }

        if ($this->isSyntheticProvider($job->provider)) {
            return AiPriceSnapshot::notBillable();
        }

        $rule = AiPricingRule::activeFor(
            (string) $job->provider,
            $job->model_name,
            $job->model_version,
        );

        if ($rule === null) {
            throw AiBillingException::pricingUnavailable((string) $job->provider);
        }

        return new AiPriceSnapshot(
            billable: true,
            ruleReference: (string) $rule->getKey(),
            unitPriceCredits: (int) $rule->unit_price_credits,
        );
    }
}
