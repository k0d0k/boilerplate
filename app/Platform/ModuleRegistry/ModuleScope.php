<?php

namespace App\Platform\ModuleRegistry;

/**
 * The scopes a module installation / entitlement can be bound to.
 *
 * Brand, tenant and branch are three distinct concepts and are never collapsed
 * into one another (see docs/DATA_ARCHITECTURE.md). "Platform" is the
 * catalog-level scope held by {@see Models\RegisteredModule}.
 */
final class ModuleScope
{
    public const PLATFORM = 'platform';

    public const BRAND = 'brand';

    public const TENANT = 'tenant';

    public const BRANCH = 'branch';

    /** @var list<string> */
    public const ALL = [
        self::PLATFORM,
        self::BRAND,
        self::TENANT,
        self::BRANCH,
    ];

    /** @var list<string> */
    public const SCOPED = [
        self::BRAND,
        self::TENANT,
        self::BRANCH,
    ];
}
