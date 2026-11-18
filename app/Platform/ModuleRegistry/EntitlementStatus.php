<?php

namespace App\Platform\ModuleRegistry;

/**
 * Lifecycle of a module entitlement grant.
 *
 * Only {@see GRANTED} is honoured; an entitlement can never become active
 * implicitly (it must be explicitly granted).
 */
final class EntitlementStatus
{
    public const PENDING = 'pending';

    public const GRANTED = 'granted';

    public const REVOKED = 'revoked';
}
