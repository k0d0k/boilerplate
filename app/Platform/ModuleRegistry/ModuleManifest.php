<?php

namespace App\Platform\ModuleRegistry;

/**
 * Immutable declaration of one module (Phase 1B-4).
 *
 * This is the module-to-platform contract from docs/MODULE_SYSTEM.md: a module
 * declares its identity, the panels it may register into, the permissions it
 * requires, its dependencies, its supported locales, its scope compatibility
 * and whether an active subscription is additionally required.
 *
 * The declaration is metadata only. It grants nothing by itself: the
 * server-side FeatureGate still requires a verified entitlement (and, when
 * declared, a verified subscription) before a module may execute.
 */
final class ModuleManifest
{
    /**
     * Every platform-provided capability dependency is named with this prefix,
     * keeping platform dependencies distinct from business module keys.
     */
    public const PLATFORM_DEPENDENCY_PREFIX = 'platform.';

    /**
     * @param  list<string>  $allowedPanels
     * @param  list<string>  $requiredPermissions
     * @param  list<string>  $requiredDependencies
     * @param  list<string>  $supportedLocales
     * @param  list<string>  $scopeCompatibility
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $version,
        public readonly string $minPlatformVersion,
        public readonly array $allowedPanels,
        public readonly array $requiredPermissions = [],
        public readonly array $requiredDependencies = [],
        public readonly array $supportedLocales = [],
        public readonly array $scopeCompatibility = [ModuleScope::TENANT],
        public readonly bool $requiresSubscription = false,
        public readonly bool $installed = true,
        public readonly string $health = ModuleHealth::HEALTHY,
        public readonly string $status = ModuleStatus::ENABLED,
        public readonly ?string $filamentRegistration = null,
    ) {}

    public function allowsPanel(string $panelId): bool
    {
        return in_array($panelId, $this->allowedPanels, true);
    }

    /**
     * True when the module declares compatibility with the given scope
     * (platform / brand / tenant / branch).
     */
    public function supportsScope(string $scope): bool
    {
        return in_array($scope, $this->scopeCompatibility, true);
    }

    public function isEnabled(): bool
    {
        return $this->status === ModuleStatus::ENABLED;
    }

    public function isHealthy(): bool
    {
        return $this->health === ModuleHealth::HEALTHY;
    }

    /**
     * Fail-closed platform-version compatibility check.
     */
    public function isCompatibleWithPlatform(string $platformVersion): bool
    {
        if ($this->minPlatformVersion === '' || $platformVersion === '') {
            return false;
        }

        return version_compare($platformVersion, $this->minPlatformVersion, '>=');
    }

    public function entitlementRequirement(): string
    {
        return $this->requiresSubscription ? 'subscription' : 'entitlement';
    }

    /**
     * True when a required dependency names a platform capability rather than a
     * business module (see {@see PLATFORM_DEPENDENCY_PREFIX}).
     */
    public static function isPlatformDependency(string $dependency): bool
    {
        return str_starts_with($dependency, self::PLATFORM_DEPENDENCY_PREFIX);
    }
}
