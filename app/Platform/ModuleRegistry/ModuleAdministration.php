<?php

namespace App\Platform\ModuleRegistry;

use App\Models\User;
use App\Platform\FeatureGate\FeatureGate;
use App\Platform\FeatureGate\ModuleDependencyValidator;
use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use Illuminate\Support\Collection;

/**
 * Phase 7D — server-side module administration (Platform governance).
 *
 * The single write path behind the Principal Module Manager and Feature Access
 * screens. It reuses the SAME registry, dependency validator and central tables
 * the {@see FeatureGate} already reads, so what an
 * administrator enables here is exactly what the gate honours at request time —
 * no parallel permission system is introduced.
 *
 * Every mutation:
 *  - is bound to an explicit, verified scope — either ONE tenant (per-tenant
 *    installation / entitlement) or the platform catalog (the global master
 *    switch); nothing is ever granted implicitly to another scope,
 *  - verifies the module is a TRUSTED, declared, scope-compatible module,
 *  - refuses to enable a module whose dependencies are unsatisfied,
 *  - records an allowed/denied {@see ModuleAuditLog} row (redacted, no clinical
 *    payload) for the acting principal.
 *
 * Disabling is non-destructive: it never drops a tenant database or clinical
 * data — it only flips the installation/entitlement/catalog state, and the gate
 * fails closed as soon as the applicable switch is disabled.
 */
final class ModuleAdministration
{
    public function __construct(
        private readonly RegistersModules $registry,
        private readonly ModuleDependencyValidator $dependencies,
    ) {}

    public static function tenantScope(string $tenantId): ModuleScopeKey
    {
        return new ModuleScopeKey(ModuleScope::TENANT, $tenantId);
    }

    /**
     * Installed modules from the central catalog (the platform master switch).
     *
     * @return Collection<int, RegisteredModule>
     */
    public function installedModules(): Collection
    {
        return RegisteredModule::query()
            ->where('is_installed', true)
            ->orderBy('name')
            ->get();
    }

    public function installationFor(string $moduleKey, string $tenantId): ?ModuleInstallation
    {
        return ModuleInstallation::query()
            ->where('module_key', $moduleKey)
            ->where('scope', ModuleScope::TENANT)
            ->where('scope_key', $tenantId)
            ->first();
    }

    public function entitlementFor(string $moduleKey, string $tenantId): ?ModuleEntitlement
    {
        return ModuleEntitlement::query()
            ->where('module_key', $moduleKey)
            ->where('scope', ModuleScope::TENANT)
            ->where('scope_key', $tenantId)
            ->first();
    }

    /**
     * The first unsatisfied dependency for the module at the tenant scope, or
     * null when the whole dependency graph is satisfied.
     */
    public function dependencyFailure(string $moduleKey, string $tenantId): ?string
    {
        $manifest = $this->registry->find($moduleKey);

        if ($manifest === null) {
            return 'unknown_module';
        }

        return $this->dependencies->failure($manifest, self::tenantScope($tenantId));
    }

    /**
     * Enable a trusted module for ONE tenant: install + grant its entitlement.
     *
     * Denies (and audits) an unknown module, a module not declared for the
     * tenant scope, or one whose dependencies are unsatisfied — the gate would
     * deny it anyway, so nothing invalid is ever written.
     *
     * `$auditAction` names the audit vocabulary for the caller's context (the
     * default `enable` for the Module Manager; the one-click clinic setup logs
     * the same activation as `activate`). Only the recorded action differs —
     * the write path, the preconditions and the result are identical.
     */
    public function enable(string $moduleKey, string $tenantId, ?User $actor = null, string $auditAction = 'enable'): bool
    {
        $failure = $this->precondition($moduleKey, $tenantId);

        if ($failure !== null) {
            $this->audit($moduleKey, $auditAction, ModuleAuditLog::RESULT_DENIED, $failure, ModuleScope::TENANT, $tenantId, $actor);

            return false;
        }

        ModuleInstallation::query()->updateOrCreate(
            ['module_key' => $moduleKey, 'scope' => ModuleScope::TENANT, 'scope_key' => $tenantId],
            [
                'is_installed' => true,
                'status' => ModuleStatus::ENABLED,
                'health' => ModuleHealth::HEALTHY,
                'activated_at' => now(),
                'disabled_at' => null,
            ],
        );

        ModuleEntitlement::query()->updateOrCreate(
            ['module_key' => $moduleKey, 'scope' => ModuleScope::TENANT, 'scope_key' => $tenantId],
            [
                'status' => EntitlementStatus::GRANTED,
                'subscription_required' => false,
                'granted_at' => now(),
                'expires_at' => null,
                'revoked_at' => null,
            ],
        );

        $this->audit($moduleKey, $auditAction, ModuleAuditLog::RESULT_ALLOWED, null, ModuleScope::TENANT, $tenantId, $actor);

        return true;
    }

    /**
     * Disable a module for one tenant (non-destructive): the installation is
     * disabled and its entitlement revoked. No tenant database is touched.
     */
    public function disable(string $moduleKey, string $tenantId, ?User $actor = null): void
    {
        ModuleInstallation::query()->updateOrCreate(
            ['module_key' => $moduleKey, 'scope' => ModuleScope::TENANT, 'scope_key' => $tenantId],
            [
                'is_installed' => true,
                'status' => ModuleStatus::DISABLED,
                'disabled_at' => now(),
            ],
        );

        ModuleEntitlement::query()->updateOrCreate(
            ['module_key' => $moduleKey, 'scope' => ModuleScope::TENANT, 'scope_key' => $tenantId],
            [
                'status' => EntitlementStatus::REVOKED,
                'revoked_at' => now(),
            ],
        );

        $this->audit($moduleKey, 'disable', ModuleAuditLog::RESULT_ALLOWED, null, ModuleScope::TENANT, $tenantId, $actor);
    }

    /**
     * Grant the module entitlement alone (Feature Access), leaving installation
     * state untouched. Denies an unknown/non-tenant module.
     */
    public function grantEntitlement(string $moduleKey, string $tenantId, ?User $actor = null): bool
    {
        if ($this->precondition($moduleKey, $tenantId) !== null) {
            $this->audit($moduleKey, 'grant', ModuleAuditLog::RESULT_DENIED, 'not_entitled_module', ModuleScope::TENANT, $tenantId, $actor);

            return false;
        }

        ModuleEntitlement::query()->updateOrCreate(
            ['module_key' => $moduleKey, 'scope' => ModuleScope::TENANT, 'scope_key' => $tenantId],
            [
                'status' => EntitlementStatus::GRANTED,
                'granted_at' => now(),
                'expires_at' => null,
                'revoked_at' => null,
            ],
        );

        $this->audit($moduleKey, 'grant', ModuleAuditLog::RESULT_ALLOWED, null, ModuleScope::TENANT, $tenantId, $actor);

        return true;
    }

    /**
     * Revoke a module entitlement for one tenant (Feature Access).
     */
    public function revokeEntitlement(string $moduleKey, string $tenantId, ?User $actor = null): void
    {
        ModuleEntitlement::query()->updateOrCreate(
            ['module_key' => $moduleKey, 'scope' => ModuleScope::TENANT, 'scope_key' => $tenantId],
            [
                'status' => EntitlementStatus::REVOKED,
                'revoked_at' => now(),
            ],
        );

        $this->audit($moduleKey, 'revoke', ModuleAuditLog::RESULT_ALLOWED, null, ModuleScope::TENANT, $tenantId, $actor);
    }

    /**
     * Every module declared in the trusted in-memory registry, keyed by module
     * key. This is the contract the platform is willing to run, independent of
     * any tenant; a module absent here can never be trusted.
     *
     * @return array<string, ModuleManifest>
     */
    public function declaredModules(): array
    {
        return $this->registry->all();
    }

    /**
     * Synchronise the platform catalog from the trusted in-memory registry.
     *
     * Catalog rows are METADATA ONLY — the platform master switch. They grant
     * nothing by themselves: the gate still requires a per-tenant installation
     * and entitlement. Returns the number of catalog rows written.
     */
    public function syncCatalog(): int
    {
        $count = 0;

        foreach ($this->registry->all() as $manifest) {
            RegisteredModule::query()->updateOrCreate(
                ['module_key' => $manifest->key],
                [
                    'name' => $manifest->name,
                    'version' => $manifest->version,
                    'min_platform_version' => $manifest->minPlatformVersion,
                    'allowed_panels' => $manifest->allowedPanels,
                    'required_permissions' => $manifest->requiredPermissions,
                    'required_dependencies' => $manifest->requiredDependencies,
                    'supported_locales' => $manifest->supportedLocales,
                    'scope_compatibility' => $manifest->scopeCompatibility,
                    'entitlement_requirement' => $manifest->entitlementRequirement(),
                    'is_installed' => true,
                    'health' => ModuleHealth::HEALTHY,
                    'status' => ModuleStatus::ENABLED,
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * The platform-wide catalog row (the master switch) for a module, or null
     * when the module has never been trusted on this platform.
     */
    public function catalogFor(string $moduleKey): ?RegisteredModule
    {
        return RegisteredModule::query()->where('module_key', $moduleKey)->first();
    }

    /**
     * The semantic version of the running platform (for contract compatibility).
     */
    public function platformVersion(): string
    {
        return $this->registry->platformVersion();
    }

    /**
     * The first unsatisfied dependency for the module at the PLATFORM scope, or
     * null when the whole dependency graph is satisfied platform-wide.
     */
    public function platformDependencyFailure(string $moduleKey): ?string
    {
        $manifest = $this->registry->find($moduleKey);

        if ($manifest === null) {
            return 'unknown_module';
        }

        return $this->dependencies->failure($manifest, ModuleScopeKey::platform());
    }

    /**
     * Trust and globally enable a declared module — the platform catalog master
     * switch, mirroring {@see Console\SetupClinicCommand}'s catalog sync.
     *
     * Denies (and audits) an undeclared module or one whose platform-scope
     * dependencies are unsatisfied, so nothing invalid is ever written. Enabling
     * globally grants nothing to any tenant on its own: the gate still requires
     * a per-tenant installation and entitlement.
     */
    public function enableGlobally(string $moduleKey, ?User $actor = null): bool
    {
        $manifest = $this->registry->find($moduleKey);

        if ($manifest === null) {
            $this->audit($moduleKey, 'enable_global', ModuleAuditLog::RESULT_DENIED, 'unknown_module', ModuleScope::PLATFORM, '', $actor);

            return false;
        }

        $failure = $this->dependencies->failure($manifest, ModuleScopeKey::platform());

        if ($failure !== null) {
            $this->audit($moduleKey, 'enable_global', ModuleAuditLog::RESULT_DENIED, $failure, ModuleScope::PLATFORM, '', $actor);

            return false;
        }

        RegisteredModule::query()->updateOrCreate(
            ['module_key' => $moduleKey],
            [
                'name' => $manifest->name,
                'version' => $manifest->version,
                'min_platform_version' => $manifest->minPlatformVersion,
                'allowed_panels' => $manifest->allowedPanels,
                'required_permissions' => $manifest->requiredPermissions,
                'required_dependencies' => $manifest->requiredDependencies,
                'supported_locales' => $manifest->supportedLocales,
                'scope_compatibility' => $manifest->scopeCompatibility,
                'entitlement_requirement' => $manifest->entitlementRequirement(),
                'is_installed' => true,
                'health' => ModuleHealth::HEALTHY,
                'status' => ModuleStatus::ENABLED,
            ],
        );

        $this->audit($moduleKey, 'enable_global', ModuleAuditLog::RESULT_ALLOWED, null, ModuleScope::PLATFORM, '', $actor);

        return true;
    }

    /**
     * Globally disable a trusted module (the platform catalog master switch),
     * without deleting any tenant installation, entitlement or clinical data.
     *
     * The catalog row is kept (still installed) but flipped to disabled, so the
     * gate fails closed everywhere until it is re-enabled. Disabling a module
     * that was never trusted is a safe no-op.
     */
    public function disableGlobally(string $moduleKey, ?User $actor = null): void
    {
        RegisteredModule::query()
            ->where('module_key', $moduleKey)
            ->update([
                'is_installed' => true,
                'status' => ModuleStatus::DISABLED,
                'updated_at' => now(),
            ]);

        $this->audit($moduleKey, 'disable_global', ModuleAuditLog::RESULT_ALLOWED, null, ModuleScope::PLATFORM, '', $actor);
    }

    /**
     * The precondition failure for a module/tenant pair, or null when the module
     * is trusted, declared and tenant-scope-compatible.
     */
    private function precondition(string $moduleKey, string $tenantId): ?string
    {
        $manifest = $this->registry->find($moduleKey);

        if ($manifest === null) {
            return 'unknown_module';
        }

        if (! $manifest->supportsScope(ModuleScope::TENANT)) {
            return 'unsupported_scope';
        }

        return $this->dependencies->failure($manifest, self::tenantScope($tenantId));
    }

    private function audit(
        string $moduleKey,
        string $action,
        string $result,
        ?string $reason,
        string $scope,
        string $scopeKey,
        ?User $actor,
    ): void {
        ModuleAuditLog::query()->create([
            'module_key' => $moduleKey,
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'scope' => $scope,
            'scope_key' => $scopeKey,
            'actor_id' => $actor?->getKey(),
            'metadata' => null,
        ]);
    }
}
