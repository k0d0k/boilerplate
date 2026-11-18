<?php

namespace Modules\KnowledgeBase\Support;

/**
 * WS9 — the two document scopes of the SINA knowledge base.
 *
 * SCOPE_PLATFORM: general educational knowledge curated by the Principal and
 * globally published; visible to every tenant that has opted into platform
 * grounding. SCOPE_TENANT: clinic reference material visible ONLY within the
 * owning tenant.
 *
 * The scope is always paired with an opaque `scope_key` (the tenant ULID for a
 * tenant scope, an empty string for the platform scope) so a single indexed
 * equality check isolates tenants and no client can choose a scope.
 */
final class KnowledgeScope
{
    public const PLATFORM = 'platform';

    public const TENANT = 'tenant';

    /** @var list<string> */
    public const ALL = [
        self::PLATFORM,
        self::TENANT,
    ];

    public static function keyFor(?string $tenantId): string
    {
        return $tenantId === null || $tenantId === '' ? '' : $tenantId;
    }
}
