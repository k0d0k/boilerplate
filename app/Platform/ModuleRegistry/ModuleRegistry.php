<?php

namespace App\Platform\ModuleRegistry;

use App\Platform\FeatureGate\FeatureGate;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Providers\ModuleRegistryServiceProvider;

/**
 * In-memory module registry (Phase 1B-4).
 *
 * Populated once per request by {@see ModuleRegistryServiceProvider}
 * from the module packages on disk. The registry is deliberately small and
 * read-mostly: it answers "what does this module declare?" — never "may this
 * identity use it?". That second question belongs to
 * {@see FeatureGate}.
 */
final class ModuleRegistry implements RegistersModules
{
    /**
     * @var array<string, ModuleManifest>
     */
    private array $manifests = [];

    public function register(ModuleManifest $manifest): void
    {
        $this->manifests[$manifest->key] = $manifest;
    }

    public function has(string $key): bool
    {
        return isset($this->manifests[$key]);
    }

    public function find(string $key): ?ModuleManifest
    {
        return $this->manifests[$key] ?? null;
    }

    /**
     * @return array<string, ModuleManifest>
     */
    public function all(): array
    {
        return $this->manifests;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->manifests);
    }

    public function platformVersion(): string
    {
        return (string) config('lba.platform.version', '3.0.0');
    }

    public function isCompatible(string $key): bool
    {
        $manifest = $this->find($key);

        return $manifest !== null && $manifest->isCompatibleWithPlatform($this->platformVersion());
    }
}
