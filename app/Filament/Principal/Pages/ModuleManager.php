<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\ModuleAdministration;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Phase 7D — Principal Module Manager.
 *
 * Displays the INSTALLED (trusted) modules from the central catalog and lets an
 * authorized principal enable or disable each one for ONE selected tenant. It
 * never installs third-party code: only modules already declared in the trusted
 * registry are listed, and the write path ({@see ModuleAdministration}) enforces
 * dependency checks and records audit entries.
 *
 * Access and every mutation are re-derived server side from the acting
 * principal's `platform.module.manage` permission — UI visibility is never the
 * authorization boundary.
 */
class ModuleManager extends Page
{
    use AuthorizesPrincipalResources;

    protected static ?string $slug = 'modules';

    protected static ?int $navigationSort = 3;

    /**
     * The module manager is served canonically by the TailAdmin surface
     * (`principal.tailadmin.modules.*`), so this interim Filament page keeps its
     * route but no longer adds a duplicate sidebar entry.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected string $view = 'filament.principal.pages.module-manager';

    /** The tenant whose module enablement is being administered. */
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_MODULE_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.principal.module_manager');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.modules.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.modules.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.modules.subtitle');
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
     * One row per installed module with its per-tenant state.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->tenantId === null || $this->tenantId === '') {
            return [];
        }

        $admin = app(ModuleAdministration::class);
        $rows = [];

        foreach ($admin->installedModules() as $module) {
            $key = (string) $module->module_key;
            $installation = $admin->installationFor($key, $this->tenantId);
            $entitlement = $admin->entitlementFor($key, $this->tenantId);

            $rows[] = [
                'key' => $key,
                'name' => (string) $module->name,
                'version' => (string) $module->version,
                'display_status' => (string) $module->status,
                'enabled' => $installation?->isEnabled() === true,
                'entitled' => $entitlement?->isGranted() === true,
                'dependency' => $admin->dependencyFailure($key, $this->tenantId),
            ];
        }

        return $rows;
    }

    public function enable(string $moduleKey): void
    {
        $this->authorizeManagement();
        $tenant = $this->requireTenant();

        $enabled = app(ModuleAdministration::class)->enable($moduleKey, (string) $tenant->getKey(), $this->actor());

        if ($enabled) {
            Notification::make()->success()->title(__('lba.modules.enabled'))->send();

            return;
        }

        Notification::make()->danger()->title(__('lba.modules.enable_denied'))->send();
    }

    public function disable(string $moduleKey): void
    {
        $this->authorizeManagement();
        $tenant = $this->requireTenant();

        app(ModuleAdministration::class)->disable($moduleKey, (string) $tenant->getKey(), $this->actor());

        Notification::make()->success()->title(__('lba.modules.disabled'))->send();
    }

    /**
     * Human-readable label for a dependency/gate denial reason.
     */
    public function reasonLabel(string $reason): string
    {
        return __("lba.modules.reason_{$reason}");
    }

    private function authorizeManagement(): void
    {
        abort_unless(static::principalCan(PermissionKey::PLATFORM_MODULE_MANAGE), 403);
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
