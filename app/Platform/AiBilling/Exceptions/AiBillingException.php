<?php

namespace App\Platform\AiBilling\Exceptions;

use RuntimeException;

/**
 * Phase 6 — a rejected AI wallet operation (CENTRAL billing domain).
 *
 * Every factory carries a stable, non-identifying error code and a safe message
 * that never contains a credential, a patient identity or clinical content. The
 * LBA analysis layer translates it into an operator-facing AI analysis error.
 */
final class AiBillingException extends RuntimeException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function walletMissing(string $tenantId): self
    {
        return new self("No AI credit wallet exists for tenant [{$tenantId}].", 'wallet_missing');
    }

    public static function insufficientCredits(int $required, int $available): self
    {
        return new self(
            "Insufficient AI credits: {$required} required, {$available} available.",
            'insufficient_credits',
        );
    }

    public static function pricingUnavailable(string $provider): self
    {
        return new self("No active AI pricing rule is configured for provider [{$provider}].", 'pricing_unavailable');
    }

    public static function invalidAmount(int $units): self
    {
        return new self("The credit amount [{$units}] must be a positive integer.", 'invalid_amount');
    }

    /**
     * A stable, non-identifying code. `insufficient_credits` is the only code
     * that is expected to surface to an operator as a normal outcome.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
