<?php

namespace App\Platform\Filament\Contracts;

use Filament\Panel;

/**
 * Module-to-panel registration contract (Phase 1B-4).
 *
 * A module implements this and names the implementation in its `module.json`
 * (`lba.filament_registration`). The platform calls it once per panel, and only
 * for panels both the module manifest and the registration itself allow — so a
 * module can never register a page, resource or widget into an unauthorized
 * panel, and panel providers never have to know about any module.
 *
 * Shared domain services and APIs stay independent of these Filament
 * implementations (docs/MODULE_SYSTEM.md).
 */
interface FilamentRegistration
{
    /**
     * The module key this registration belongs to.
     */
    public function key(): string;

    /**
     * Panels this registration may target. Must be a subset of the manifest's
     * allowed panels; a mismatch is rejected at registration time.
     *
     * @return list<string>
     */
    public function panels(): array;

    /**
     * Attach components to an already-authorized panel.
     */
    public function register(Panel $panel): void;

    /**
     * Run once the panel has booted.
     */
    public function boot(Panel $panel): void;
}
