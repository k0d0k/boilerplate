<?php

namespace Tests\Feature\Platform\ModuleRegistry;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\ModuleDiscovery;
use App\Platform\ModuleRegistry\ModuleManifest;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\ModuleRegistry\ModuleStatus;
use Composer\InstalledVersions;
use Tests\TestCase;

/**
 * Phase 1B-4 module registry foundation.
 *
 * Proves the platform-agnostic module contract is discovered from disk, that the
 * registry is compatible with the pinned Laravel 13 / Filament 5 / Livewire 4
 * stack, and that a module may not be reported compatible when it demands a
 * newer platform.
 */
class ModuleRegistryFoundationTest extends TestCase
{
    private const MODULE = 'demo.showcase';

    public function test_the_platform_runs_on_the_contracted_framework_majors(): void
    {
        // Assert the contracted majors straight from Composer's installed
        // metadata. `InstalledVersions::satisfies()` is intentionally avoided
        // because it pulls in composer/semver, which is not a runtime
        // dependency of the application.
        $this->assertSame('13', $this->majorOf('laravel/framework'));
        $this->assertSame('13', explode('.', app()->version())[0]);
        $this->assertSame('5', $this->majorOf('filament/filament'));
        $this->assertSame('4', $this->majorOf('livewire/livewire'));
    }

    public function test_the_demonstration_module_is_discovered_from_disk(): void
    {
        $manifests = (new ModuleDiscovery)->discover();

        $keys = array_map(static fn (ModuleManifest $manifest): string => $manifest->key, $manifests);

        $this->assertContains(self::MODULE, $keys);
    }

    public function test_discovery_ignores_a_package_without_an_lba_declaration(): void
    {
        $manifest = (new ModuleDiscovery)->manifestFromData(['name' => 'PlainPackage']);

        $this->assertNull($manifest);
    }

    public function test_the_registry_is_populated_with_the_discovered_module(): void
    {
        $registry = $this->app->make(RegistersModules::class);

        $this->assertTrue($registry->has(self::MODULE));

        // Phase 3 ships the Patients and Appointments business modules, Phase 4
        // ships the LBA examination module, Workstream 2 ships the Consultations
        // module, Workstream 7 the Practitioners module, Workstream 8 the
        // Commerce module and Workstream 9 the Knowledge Base module; the
        // demonstration module completes the set. All discovered manifests are
        // registered.
        $keys = $registry->keys();
        sort($keys);

        $this->assertSame(['appointments', 'commerce', 'consultations', 'demo.showcase', 'knowledge_base', 'lba', 'patients', 'practitioners'], $keys);

        $manifest = $registry->find(self::MODULE);

        $this->assertNotNull($manifest);
        $this->assertSame('DemoShowcase', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame([PanelAccessPolicy::PANEL_CLINIC], $manifest->allowedPanels);
        $this->assertSame(['tenant.profile.manage'], $manifest->requiredPermissions);
        $this->assertSame([ModuleScope::TENANT], $manifest->scopeCompatibility);
        $this->assertSame(['id', 'ms-MY', 'en'], $manifest->supportedLocales);
        $this->assertTrue($manifest->allowsPanel(PanelAccessPolicy::PANEL_CLINIC));
        $this->assertFalse($manifest->allowsPanel(PanelAccessPolicy::PANEL_PRINCIPAL));
        $this->assertFalse($manifest->allowsPanel(PanelAccessPolicy::PANEL_PATIENT));
    }

    public function test_registering_a_module_twice_replaces_the_declaration_by_key(): void
    {
        $registry = $this->app->make(RegistersModules::class);

        $registry->register(new ModuleManifest(
            key: 'demo.replaced',
            name: 'First',
            version: '1.0.0',
            minPlatformVersion: '3.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
            status: ModuleStatus::DISABLED,
        ));

        $registry->register(new ModuleManifest(
            key: 'demo.replaced',
            name: 'Second',
            version: '2.0.0',
            minPlatformVersion: '3.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
        ));

        $this->assertSame('Second', $registry->find('demo.replaced')?->name);
        $this->assertSame('2.0.0', $registry->find('demo.replaced')?->version);
    }

    public function test_the_demonstration_module_is_compatible_with_the_current_platform(): void
    {
        $registry = $this->app->make(RegistersModules::class);

        $this->assertSame('3.0.0', $registry->platformVersion());
        $this->assertTrue($registry->isCompatible(self::MODULE));
        $this->assertFalse($registry->isCompatible('nope.nope'));
    }

    public function test_a_module_demanding_a_newer_platform_is_not_compatible(): void
    {
        $registry = $this->app->make(RegistersModules::class);

        $registry->register(new ModuleManifest(
            key: 'demo.future',
            name: 'Future',
            version: '1.0.0',
            minPlatformVersion: '99.0.0',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
        ));

        $registry->register(new ModuleManifest(
            key: 'demo.no-minimum',
            name: 'No Minimum',
            version: '1.0.0',
            minPlatformVersion: '',
            allowedPanels: [PanelAccessPolicy::PANEL_CLINIC],
        ));

        $this->assertFalse($registry->isCompatible('demo.future'));
        $this->assertFalse($registry->isCompatible('demo.no-minimum'));
    }

    public function test_every_declared_locale_has_a_translation_catalog(): void
    {
        $manifest = $this->app->make(RegistersModules::class)->find(self::MODULE);

        $this->assertNotNull($manifest);
        $this->assertNotSame([], $manifest->supportedLocales);

        foreach ($manifest->supportedLocales as $locale) {
            $path = base_path("Modules/DemoShowcase/lang/{$locale}/demomodule.php");

            $this->assertFileExists($path, "Module declares locale [{$locale}] but ships no catalog.");
        }
    }

    public function test_module_translation_catalogs_are_key_identical_across_locales(): void
    {
        $catalogs = [];

        foreach (['id', 'ms-MY', 'en'] as $locale) {
            $catalogs[$locale] = $this->flatten(require base_path("Modules/DemoShowcase/lang/{$locale}/demomodule.php"));
        }

        $baseline = $catalogs['id'];

        $this->assertNotSame([], $baseline);

        foreach ($catalogs as $locale => $keys) {
            $missing = array_diff($baseline, $keys);
            $extra = array_diff($keys, $baseline);

            $this->assertSame([], array_values($missing), "Locale [{$locale}] is missing keys: ".implode(', ', $missing));
            $this->assertSame([], array_values($extra), "Locale [{$locale}] has stray keys: ".implode(', ', $extra));
        }
    }

    public function test_the_base_catalog_exposes_the_module_denial_message_in_every_locale(): void
    {
        foreach (['id', 'ms-MY', 'en'] as $locale) {
            $this->assertArrayHasKey(
                'module.access_denied',
                array_flip($this->flatten(require base_path("lang/{$locale}/lba.php"))),
                "The base [{$locale}] catalog is missing the module.access_denied key.",
            );
        }
    }

    /**
     * The installed major version of a Composer package, without requiring
     * composer/semver.
     */
    private function majorOf(string $package): string
    {
        $version = InstalledVersions::getVersion($package);

        $this->assertNotNull($version, "Package [{$package}] is not installed.");

        return explode('.', $version)[0];
    }

    /**
     * @param  array<string, mixed>  $array
     * @return list<string>
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $dotted));

                continue;
            }

            $keys[] = $dotted;
        }

        return $keys;
    }
}
