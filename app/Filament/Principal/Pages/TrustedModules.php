<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\ModuleAdministration;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * WS4.6 — Principal Trusted Module / Plugin Registry.
 *
 * The PLATFORM-level counterpart of the per-tenant Module Manager: it browses
 * the TRUSTED registry (only modules declared on disk with an `lba` manifest are
 * ever listed), surfaces each module's declared contract — version, minimum
 * platform version, compatibility, allowed panels, scope compatibility,
 * dependencies, supported locales and entitlement requirement — together with
 * its platform catalog master-switch state, and lets an authorized principal
 * enable or disable a module GLOBALLY.
 *
 * It never installs third-party code: the write path ({@see ModuleAdministration})
 * re-verifies the module is declared, refuses a module whose platform-scope
 * dependencies are unsatisfied, and audits every change. Enabling globally
 * grants nothing to any tenant on its own — per-tenant installation and
 * entitlement stay required, so tenant isolation is never weakened.
 *
 * Access and every mutation are re-derived server side from the acting
 * principal's `platform.module.manage` permission.
 */
class TrustedModules extends Page
{
    use AuthorizesPrincipalResources;

    protected static ?string $slug = 'trusted-modules';

    protected static ?int $navigationSort = 5;

    /**
     * The trusted-module registry is served canonically by the TailAdmin
     * "Manajer Modul" surface (`principal.tailadmin.modules.*`), so this interim
     * Filament page keeps its route but no longer adds a duplicate sidebar entry.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected string $view = 'filament.principal.pages.trusted-modules';

    public static function canAccess(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_MODULE_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.principal.trusted_modules');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.trusted_modules.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.trusted_modules.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.trusted_modules.subtitle');
    }

    /**
     * One row per TRUSTED (declared) module, merged with its platform catalog
     * master-switch state. Undeclared modules can never appear here.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $admin = app(ModuleAdministration::class);
        $platformVersion = $admin->platformVersion();
        $rows = [];

        foreach ($admin->declaredModules() as $key => $manifest) {
            $catalog = $admin->catalogFor($key);

            $rows[] = [
                'key' => $key,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'min_platform' => $manifest->minPlatformVersion,
                'compatible' => $manifest->isCompatibleWithPlatform($platformVersion),
                'panels' => $manifest->allowedPanels,
                'scopes' => $manifest->scopeCompatibility,
                'dependencies' => $manifest->requiredDependencies,
                'locales' => $manifest->supportedLocales,
                'entitlement' => $manifest->entitlementRequirement(),
                'installed' => $catalog?->is_installed === true,
                'enabled' => $catalog?->isEnabled() === true,
                'healthy' => $catalog?->isHealthy() === true,
                'dependency' => $admin->platformDependencyFailure($key),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    public function enableGlobally(string $moduleKey): void
    {
        $this->authorizeManagement();

        $enabled = app(ModuleAdministration::class)->enableGlobally($moduleKey, $this->actor());

        Notification::make()
            ->{$enabled ? 'success' : 'danger'}()
            ->title($enabled ? __('lba.trusted_modules.enabled_globally') : __('lba.trusted_modules.enable_denied'))
            ->send();
    }

    public function disableGlobally(string $moduleKey): void
    {
        $this->authorizeManagement();

        app(ModuleAdministration::class)->disableGlobally($moduleKey, $this->actor());

        Notification::make()->success()->title(__('lba.trusted_modules.disabled_globally'))->send();
    }

    /**
     * Human-readable label for a dependency/gate denial reason.
     */
    public function reasonLabel(string $reason): string
    {
        return __("lba.modules.reason_{$reason}");
    }

    /**
     * Human-readable label for an entitlement requirement kind.
     */
    public function entitlementLabel(string $requirement): string
    {
        return __("lba.trusted_modules.entitlement_{$requirement}");
    }

    private function authorizeManagement(): void
    {
        abort_unless(static::principalCan(PermissionKey::PLATFORM_MODULE_MANAGE), 403);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
