<?php

namespace Modules\LBA\AI\Exceptions;

use RuntimeException;

/**
 * Phase 5 — a rejected AI analysis operation.
 *
 * Every factory returns a safe, non-identifying message plus a stable error
 * code. A message never contains a credential, a signed URL, a gateway secret,
 * a patient identity or any clinical content; callers surface it directly to an
 * operator, and the error code is what gets persisted on a failed job.
 */
final class AiAnalysisException extends RuntimeException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('AI analysis is not enabled on this environment yet.', 'ai_disabled');
    }

    /**
     * A synthetic/mock provider is never permitted in production.
     */
    public static function productionMockProhibited(): self
    {
        return new self(
            'A synthetic AI provider may not be used in production.',
            'production_mock_prohibited',
        );
    }

    public static function insufficientCredits(): self
    {
        return new self(
            'This clinic does not have enough AI credits for this analysis.',
            'insufficient_credits',
        );
    }

    public static function pricingUnavailable(): self
    {
        return new self(
            'No approved AI pricing is configured for this model.',
            'pricing_unavailable',
        );
    }

    public static function walletUnavailable(): self
    {
        return new self(
            'The clinic AI credit wallet is not available for billing.',
            'wallet_unavailable',
        );
    }

    public static function modeNotProcessable(string $mode): self
    {
        return new self("Examination mode [{$mode}] cannot run AI vision analysis.", 'mode_not_processable');
    }

    public static function consentRequired(): self
    {
        return new self('A recorded image-processing consent is required before AI analysis can run.', 'consent_required');
    }

    public static function notEditable(string $status): self
    {
        return new self("An examination in status [{$status}] can no longer be analysed.", 'not_editable');
    }

    public static function imageNotOnExamination(): self
    {
        return new self('The selected image does not belong to this examination.', 'image_not_on_examination');
    }

    public static function imageUnreadable(): self
    {
        return new self('The original microscopy image could not be read.', 'image_unreadable');
    }

    public static function gatewayNotConfigured(): self
    {
        return new self('The AI vision gateway is not configured.', 'gateway_not_configured');
    }

    public static function gatewayNotAllowed(): self
    {
        return new self('The configured AI vision gateway host is not on the allowlist.', 'gateway_not_allowed');
    }

    public static function gatewayUnavailable(): self
    {
        return new self('The AI vision gateway is currently unavailable.', 'gateway_unavailable');
    }

    public static function invalidResponse(string $reason): self
    {
        return new self("The AI vision gateway returned an invalid response: {$reason}", 'invalid_response');
    }

    public static function retryLimitReached(int $limit): self
    {
        return new self("This analysis job reached its retry limit of {$limit}.", 'retry_limit_reached');
    }

    public static function stateNotRetryable(string $status): self
    {
        return new self("An analysis job in status [{$status}] cannot be retried.", 'state_not_retryable');
    }

    /**
     * A stable, non-identifying code persisted on a failed job.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
