<?php

namespace App\Platform\FeatureGate;

use App\Platform\ModuleRegistry\ModuleScope;

/**
 * One concrete scope a module is evaluated against (Phase 1B-4).
 *
 * `scopeKey` is the opaque id of the brand / tenant / branch, and is empty only
 * for the platform scope. Brand, tenant and branch are never collapsed into one
 * another — a grant made for one scope key can never satisfy another.
 */
final class ModuleScopeKey
{
    public function __construct(
        public readonly string $scope,
        public readonly string $scopeKey = '',
    ) {}

    public static function platform(): self
    {
        return new self(ModuleScope::PLATFORM, '');
    }

    public function isPlatform(): bool
    {
        return $this->scope === ModuleScope::PLATFORM;
    }
}
