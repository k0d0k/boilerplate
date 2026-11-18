<?php

namespace App\Platform\ModuleRegistry\Contracts;

use App\Platform\ModuleRegistry\ModuleManifest;

/**
 * Central module registry (Phase 1B-4).
 *
 * Holds the in-memory declarations of every module the platform has discovered.
 * A registry entry is *metadata only*: it never grants access. The server-side
 * FeatureGate still requires a verified entitlement (and, when declared, a
 * verified subscription) before a module may execute.
 */
interface RegistersModules
{
    /**
     * Declare a module. Registering the same key twice replaces the previous
     * declaration (last declaration wins, which keeps boot idempotent).
     */
    public function register(ModuleManifest $manifest): void;

    public function has(string $key): bool;

    public function find(string $key): ?ModuleManifest;

    /**
     * @return array<string, ModuleManifest> keyed by module key
     */
    public function all(): array;

    /**
     * @return list<string>
     */
    public function keys(): array;

    /**
     * The semantic version of the running platform.
     */
    public function platformVersion(): string;

    /**
     * Fail-closed platform-compatibility check for one module.
     */
    public function isCompatible(string $key): bool;
}
