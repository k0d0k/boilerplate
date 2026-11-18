<?php

namespace App\Platform\ModuleRegistry;

/**
 * Filesystem discovery of module declarations (Phase 1B-4).
 *
 * Reads the `lba` block of every `Modules/<Package>/module.json` on disk and
 * turns it into an immutable {@see ModuleManifest}. Discovery is intentionally
 * decoupled from the third-party modules package: the platform owns the
 * *contract* it reads, and a package that ships no `lba` block is simply not an
 * LBA module and is ignored.
 *
 * A discovered module is reported as installed/healthy/enabled by declaration —
 * its code does exist and does load. Runtime enablement is decided per scope by
 * the FeatureGate from the central tables, so a discovered module still grants
 * nothing until it is explicitly entitled.
 */
final class ModuleDiscovery
{
    /**
     * @return list<ModuleManifest>
     */
    public function discover(): array
    {
        $manifests = [];

        foreach ($this->manifestFiles() as $file) {
            $manifest = $this->manifestFromFile($file);

            if ($manifest !== null) {
                $manifests[] = $manifest;
            }
        }

        return $manifests;
    }

    /**
     * @return list<string>
     */
    private function manifestFiles(): array
    {
        $path = rtrim($this->modulesPath(), DIRECTORY_SEPARATOR);

        if ($path === '' || ! is_dir($path)) {
            return [];
        }

        $files = glob($path.'/*/module.json') ?: [];

        sort($files);

        return array_values($files);
    }

    private function modulesPath(): string
    {
        $configured = config('lba.modules_path');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $packagePath = config('modules.paths.modules');

        return is_string($packagePath) && $packagePath !== ''
            ? $packagePath
            : base_path('Modules');
    }

    private function manifestFromFile(string $file): ?ModuleManifest
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $this->manifestFromData($data) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function manifestFromData(array $data): ?ModuleManifest
    {
        $declaration = $data['lba'] ?? null;

        if (! is_array($declaration)) {
            return null;
        }

        $key = $declaration['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $scopes = $this->stringList($declaration['scope'] ?? null);

        return new ModuleManifest(
            key: $key,
            name: (string) ($data['name'] ?? $key),
            version: (string) ($declaration['version'] ?? '0.0.0'),
            minPlatformVersion: (string) ($declaration['min_platform'] ?? ''),
            allowedPanels: $this->stringList($declaration['filament_panels'] ?? null),
            requiredPermissions: $this->stringList($declaration['permissions'] ?? null),
            requiredDependencies: $this->stringList($declaration['requires'] ?? null),
            supportedLocales: $this->stringList($declaration['translation_locales'] ?? null),
            scopeCompatibility: $scopes === [] ? [ModuleScope::TENANT] : $scopes,
            requiresSubscription: (bool) ($declaration['requires_subscription'] ?? false),
            filamentRegistration: $this->optionalString($declaration['filament_registration'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
