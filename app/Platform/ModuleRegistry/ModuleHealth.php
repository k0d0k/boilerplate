<?php

namespace App\Platform\ModuleRegistry;

/**
 * Declared / observed health of a module.
 *
 * Only {@see HEALTHY} satisfies the FeatureGate: every other value — including
 * the fail-closed {@see UNKNOWN} default — denies access.
 */
final class ModuleHealth
{
    public const UNKNOWN = 'unknown';

    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    public const UNHEALTHY = 'unhealthy';
}
