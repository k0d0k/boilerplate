<?php

namespace App\Platform\Onboarding;

/**
 * Result of a one-click clinic setup run (Phase 3).
 *
 * Carries the per-step progress, the resulting database READINESS (the central
 * readiness gate: only a `migrated` registry row is ready) and — when a Clinic
 * Admin was invited — the single-use activation URL, which is surfaced exactly
 * once to the authorised Principal and never persisted.
 */
final class ClinicSetupResult
{
    public const STEP_DATABASE = 'database';

    public const STEP_MODULES = 'modules';

    public const STEP_ADMIN = 'admin';

    public const STEP_CONCURRENCY = 'concurrency';

    /**
     * @param  list<ClinicSetupStep>  $steps
     */
    private function __construct(
        public readonly string $tenantId,
        public readonly array $steps,
        public readonly bool $ready,
        public readonly ?string $activationUrl,
        public readonly ?string $error,
    ) {}

    /**
     * @param  list<ClinicSetupStep>  $steps
     */
    public static function completed(
        string $tenantId,
        array $steps,
        bool $ready,
        ?string $activationUrl = null,
        ?string $error = null,
    ): self {
        return new self($tenantId, $steps, $ready, $activationUrl, $error);
    }

    /**
     * A concurrent setup run already holds the per-tenant lock.
     */
    public static function busy(string $tenantId): self
    {
        return new self(
            $tenantId,
            [ClinicSetupStep::failed(self::STEP_CONCURRENCY, 'Another setup run is already in progress for this tenant.')],
            false,
            null,
            'concurrent_setup',
        );
    }

    /**
     * True when no step failed. A skipped step (e.g. no Clinic Admin supplied)
     * is not a failure.
     */
    public function succeeded(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->isFailed()) {
                return false;
            }
        }

        return true;
    }

    public function step(string $key): ?ClinicSetupStep
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        return null;
    }

    public function failedStep(): ?ClinicSetupStep
    {
        foreach ($this->steps as $step) {
            if ($step->isFailed()) {
                return $step;
            }
        }

        return null;
    }
}
