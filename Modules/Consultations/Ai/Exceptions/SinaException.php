<?php

namespace Modules\Consultations\Ai\Exceptions;

use RuntimeException;

/**
 * Workstream 2 — a rejected or failed SINA provider interaction.
 *
 * Every factory carries a stable, non-identifying error code and a safe
 * message that never contains a credential, a patient identity or clinical
 * content. Callers (the chat service, the API) map the code to a safe response.
 */
final class SinaException extends RuntimeException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $provider): self
    {
        return new self("No SINA AI provider is configured for [{$provider}].", 'provider_not_configured');
    }

    public static function unavailable(string $reason): self
    {
        return new self("The SINA AI provider is unavailable ({$reason}).", 'provider_unavailable');
    }

    public static function invalidResponse(string $reason): self
    {
        return new self("The SINA AI provider returned an unusable response ({$reason}).", 'invalid_response');
    }

    public static function walletMissing(string $tenantId, string $patientId): self
    {
        return new self("No SINA wallet exists for patient [{$patientId}] in tenant [{$tenantId}].", 'wallet_missing');
    }

    public static function insufficientCredits(int $required, int $available): self
    {
        return new self("Insufficient SINA credits: {$required} required, {$available} available.", 'insufficient_credits');
    }

    public static function invalidAmount(int $units): self
    {
        return new self("The credit amount [{$units}] must be a positive integer.", 'invalid_amount');
    }

    /**
     * The SAME turn reference is already being processed (a parallel duplicate).
     */
    public static function turnInProgress(): self
    {
        return new self('A turn with this reference is already being processed.', 'turn_in_progress');
    }

    /**
     * The SAME turn reference was previously used with DIFFERENT content.
     */
    public static function turnConflict(): self
    {
        return new self('This turn reference was already used with different content.', 'turn_reference_conflict');
    }

    /**
     * A succeeded turn exists but its stored reply can no longer be resolved.
     */
    public static function turnReplayUnavailable(): self
    {
        return new self('The stored reply for this turn reference is no longer available.', 'turn_replay_unavailable');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
