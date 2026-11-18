<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Pages\ModuleManager;
use App\Filament\Principal\Pages\TrustedModules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Principal\UploadModulePackageRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\ModuleAdministration;
use App\Platform\ModuleRegistry\TrustedModulePackages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 14 — Principal "Manajer Modul" TailAdmin screen (trusted distribution).
 *
 * The Blade counterpart of the Filament {@see TrustedModules}
 * and {@see ModuleManager} pages: it browses the
 * trusted registry, shows uploaded package provenance, and lets an authorized
 * principal upload an approved signed package, activate it for ONE tenant and
 * roll that activation back.
 *
 * Every action re-derives authorization server-side from `platform.module.manage`
 * (never from UI visibility), and all writes go through the shared
 * {@see ModuleAdministration} / {@see TrustedModulePackages} services — so the
 * FeatureGate, dependency checks, tenant scoping and audit trail are identical.
 * Uploading a package NEVER executes its code.
 */
final class ModulePackageController extends Controller
{
    use AuthorizesPrincipalResources;

    public function index(TrustedModulePackages $packages, ModuleAdministration $administration): View
    {
        $this->authorizeModules();

        $platformVersion = $administration->platformVersion();
        $modules = [];

        foreach ($administration->declaredModules() as $key => $manifest) {
            $catalog = $administration->catalogFor($key);

            $modules[] = [
                'key' => $key,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'compatible' => $manifest->isCompatibleWithPlatform($platformVersion),
                'dependencies' => $manifest->requiredDependencies,
                'enabled' => $catalog?->isEnabled() === true,
                'dependency' => $administration->platformDependencyFailure($key),
            ];
        }

        usort($modules, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return view('principal.modules.index', [
            'modules' => $modules,
            'tenants' => Tenant::query()->orderBy('name')->pluck('name', 'id')->all(),
            'packages' => $packages->packages(),
            'platformVersion' => $platformVersion,
        ]);
    }

    public function store(UploadModulePackageRequest $request, TrustedModulePackages $packages): RedirectResponse
    {
        $file = $request->file('package');

        $package = $packages->upload(
            (string) $file?->getRealPath(),
            $file?->getClientOriginalName(),
            (string) $request->input('signature', ''),
            $request->filled('publisher') ? (string) $request->string('publisher') : null,
            $this->actor(),
        );

        if (! $package->isVerified()) {
            return to_route('principal.tailadmin.modules.index')
                ->withErrors(['package' => __('lba.module_packages.rejected', [
                    'reason' => __("lba.module_packages.reason_{$package->reason}"),
                ])]);
        }

        return to_route('principal.tailadmin.modules.index')
            ->with('success', __('lba.module_packages.uploaded', ['module' => (string) $package->module_key]));
    }

    public function activate(Request $request, string $package, TrustedModulePackages $packages): RedirectResponse
    {
        $this->authorizeModules();

        $record = $packages->find($package);
        abort_if($record === null, 404);

        $data = $request->validate([
            'tenant' => ['required', 'string', Rule::exists('tenants', 'id')],
        ]);

        $activated = $packages->activate($package, (string) $data['tenant'], $this->actor());

        if (! $activated) {
            return to_route('principal.tailadmin.modules.index')
                ->withErrors(['activate' => __('lba.module_packages.activate_denied')]);
        }

        return to_route('principal.tailadmin.modules.index')
            ->with('success', __('lba.module_packages.activated', ['module' => (string) $record->module_key]));
    }

    public function rollback(string $package, TrustedModulePackages $packages): RedirectResponse
    {
        $this->authorizeModules();

        $record = $packages->find($package);
        abort_if($record === null, 404);

        $rolledBack = $packages->rollback($package, $this->actor());

        if (! $rolledBack) {
            return to_route('principal.tailadmin.modules.index')
                ->withErrors(['rollback' => __('lba.module_packages.rollback_denied')]);
        }

        return to_route('principal.tailadmin.modules.index')
            ->with('success', __('lba.module_packages.rolled_back', ['module' => (string) $record->module_key]));
    }

    private function authorizeModules(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_MODULE_MANAGE), 403);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
