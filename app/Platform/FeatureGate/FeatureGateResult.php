<?php

namespace App\Platform\FeatureGate;

/**
 * The outcome of a FeatureGate evaluation (Phase 1B-4).
 */
final class FeatureGateResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly string $moduleKey,
        public readonly ?string $panelId = null,
        public readonly ?ModuleScopeKey $scope = null,
    ) {}

    public static function allow(
        string $moduleKey,
        string $panelId,
        ModuleScopeKey $scope,
    ): self {
        return new self(true, FeatureGateReason::ALLOWED, $moduleKey, $panelId, $scope);
    }

    public static function deny(
        string $reason,
        string $moduleKey,
        ?string $panelId = null,
        ?ModuleScopeKey $scope = null,
    ): self {
        return new self(false, $reason, $moduleKey, $panelId, $scope);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }
}
