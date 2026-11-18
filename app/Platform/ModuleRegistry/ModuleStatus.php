<?php

namespace App\Platform\ModuleRegistry;

/**
 * Installation status of a module at a given scope.
 *
 * A module is only usable when every applicable scope reports
 * {@see ENABLED}; {@see DISABLED} is the fail-closed default.
 */
final class ModuleStatus
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';
}
