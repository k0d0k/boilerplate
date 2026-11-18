<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\ModuleRegistry\ModuleAdministration;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Phase 7D — Principal Feature Access.
 *
 * Manages the access a tenant actually holds, using ONLY the existing platform
 * machinery: module ENTITLEMENTS (`module_entitlements`, the same grants the
 * FeatureGate reads) and FEATURE PERMISSIONS expressed through the existing RBAC
 * roles on tenant memberships. No parallel permission system is introduced — the
 * permission surface is whatever {@see RbacCatalog} already defines.
 *
 * Access and every mutation are re-derived server side from the acting
 * principal's `platform.entitlement.manage` permission.
 */
class FeatureAccess extends Page
{
    use AuthorizesPrincipalResources;

    protected static ?string $slug = 'feature-access';

    protected static ?int $navigationSort = 4;

    /**
     * Feature access is served canonically by the TailAdmin surface
     * (`principal.tailadmin.entitlements.*`), so this interim Filament page
     * keeps its route but no longer adds a duplicate sidebar entry.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected string $view = 'filament.principal.pages.feature-access';

    /** The tenant whose entitlements / feature permissions are administered. */
    public ?string $tenantId = null;

    /** membership id => selected role keys. */
    public array $memberRoles = [];

    public static function canAccess(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_ENTITLEMENT_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.principal.feature_access');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.feature_access.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.feature_access.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.feature_access.subtitle');
    }

    /**
     * @return array<string, string>
     */
    public function tenantOptions(): array
    {
        return Tenant::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Installed modules with their entitlement state for the selected tenant.
     *
     * @return list<array<string, mixed>>
     */
    public function entitlementRows(): array
    {
        if ($this->tenantId === null || $this->tenantId === '') {
            return [];
        }

        $admin = app(ModuleAdministration::class);
        $rows = [];

        foreach ($admin->installedModules() as $module) {
            $key = (string) $module->module_key;
            $entitlement = $admin->entitlementFor($key, $this->tenantId);

            $rows[] = [
                'key' => $key,
                'name' => (string) $module->name,
                'granted' => $entitlement?->isGranted() === true,
                'status' => $entitlement?->status,
            ];
        }

        return $rows;
    }

    /**
     * The selected tenant's memberships (identity => roles).
     *
     * @return Collection<int, TenantMembership>
     */
    public function memberships(): Collection
    {
        if ($this->tenantId === null || $this->tenantId === '') {
            return collect();
        }

        return TenantMembership::query()
            ->where('tenant_id', $this->tenantId)
            ->with(['user', 'roles'])
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function assignableRoleOptions(): array
    {
        $options = [];

        foreach (RbacCatalog::membershipAssignableRoles() as $roleKey) {
            $options[$roleKey] = __("lba.roles.{$roleKey}");
        }

        return $options;
    }

    /**
     * Role => permission keys, for the read-only access matrix.
     *
     * @return array<string, list<string>>
     */
    public function roleMatrix(): array
    {
        return RbacCatalog::rolePermissions();
    }

    /**
     * Human-readable permission label.
     */
    public function permissionLabel(string $permission): string
    {
        return __("lba.permissions.{$permission}");
    }

    /**
     * Repopulate the per-membership role selections when the tenant changes.
     */
    public function updatedTenantId(): void
    {
        $this->memberRoles = $this->defaultMemberRoles();
    }

    public function grant(string $moduleKey): void
    {
        $this->authorizeManagement();
        $tenant = $this->requireTenant();

        $granted = app(ModuleAdministration::class)
            ->grantEntitlement($moduleKey, (string) $tenant->getKey(), $this->actor());

        Notification::make()
            ->{$granted ? 'success' : 'danger'}()
            ->title($granted ? __('lba.feature_access.granted') : __('lba.feature_access.grant_denied'))
            ->send();
    }

    public function revoke(string $moduleKey): void
    {
        $this->authorizeManagement();
        $tenant = $this->requireTenant();

        app(ModuleAdministration::class)
            ->revokeEntitlement($moduleKey, (string) $tenant->getKey(), $this->actor());

        Notification::make()->success()->title(__('lba.feature_access.revoked'))->send();
    }

    public function saveRoles(string $membershipId): void
    {
        $this->authorizeManagement();
        $tenant = $this->requireTenant();

        $membership = TenantMembership::query()->find($membershipId);

        // The membership must belong to the selected tenant (never trust a
        // client-supplied id across tenants).
        abort_if($membership === null || (string) $membership->tenant_id !== (string) $tenant->getKey(), 404);

        $keys = array_values(array_filter((array) ($this->memberRoles[$membershipId] ?? [])));
        $ids = Role::query()->whereIn('key', $keys)->pluck('id')->all();

        $membership->roles()->sync($ids);

        Notification::make()->success()->title(__('lba.feature_access.roles_updated'))->send();
    }

    /**
     * @return array<string, list<string>>
     */
    private function defaultMemberRoles(): array
    {
        $roles = [];

        foreach ($this->memberships() as $membership) {
            $roles[(string) $membership->getKey()] = $membership->roles->pluck('key')->all();
        }

        return $roles;
    }

    private function authorizeManagement(): void
    {
        abort_unless(static::principalCan(PermissionKey::PLATFORM_ENTITLEMENT_MANAGE), 403);
    }

    private function requireTenant(): Tenant
    {
        $tenant = Tenant::query()->find($this->tenantId);

        abort_if($tenant === null, 404);

        return $tenant;
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
