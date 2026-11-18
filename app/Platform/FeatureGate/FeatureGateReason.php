<?php

namespace App\Platform\FeatureGate;

/**
 * Why a module access decision resolved the way it did (Phase 1B-4).
 *
 * Every value except {@see ALLOWED} is a denial. The reason is stable and safe
 * to log: it never contains patient, clinical or credential data.
 */
final class FeatureGateReason
{
    public const ALLOWED = 'allowed';

    public const UNKNOWN_MODULE = 'unknown_module';

    public const INCOMPATIBLE_PLATFORM = 'incompatible_platform';

    public const PANEL_NOT_ALLOWED = 'panel_not_allowed';

    public const UNAUTHORIZED = 'unauthorized';

    public const UNKNOWN_PERMISSION = 'unknown_permission';

    public const NO_VERIFIED_SCOPE = 'no_verified_scope';

    public const NOT_INSTALLED = 'not_installed';

    public const DISABLED = 'disabled';

    public const UNHEALTHY = 'unhealthy';

    public const NOT_ENTITLED = 'not_entitled';

    public const NO_SUBSCRIPTION = 'no_active_subscription';

    public const MISSING_DEPENDENCY = 'missing_dependency';

    public const DISABLED_DEPENDENCY = 'disabled_dependency';

    public const UNHEALTHY_DEPENDENCY = 'unhealthy_dependency';

    public const INCOMPATIBLE_DEPENDENCY = 'incompatible_dependency';

    public const CIRCULAR_DEPENDENCY = 'circular_dependency';

    public const UNSATISFIED_PLATFORM_DEPENDENCY = 'unsatisfied_platform_dependency';
}
