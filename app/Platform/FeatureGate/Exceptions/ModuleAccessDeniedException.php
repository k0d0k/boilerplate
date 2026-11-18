<?php

namespace App\Platform\FeatureGate\Exceptions;

use App\Platform\FeatureGate\FeatureGateResult;
use RuntimeException;

/**
 * Thrown when a protected module action is attempted without a satisfied gate
 * (Phase 1B-4).
 */
final class ModuleAccessDeniedException extends RuntimeException
{
    private function __construct(
        public readonly FeatureGateResult $result,
    ) {
        parent::__construct(sprintf(
            'Module [%s] access denied (%s).',
            $result->moduleKey,
            $result->reason,
        ));
    }

    public static function forResult(FeatureGateResult $result): self
    {
        return new self($result);
    }
}
