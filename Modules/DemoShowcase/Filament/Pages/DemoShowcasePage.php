<?php

namespace Modules\DemoShowcase\Filament\Pages;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\FeatureGate\Middleware\EnsureModuleAccess;
use App\Platform\FeatureGate\ModuleGate;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 1B-4 demonstration page — non-clinical, no patient data, no billing.
 *
 * It exists to prove three things:
 *  - the module registers its page only in the Clinic panel;
 *  - the page is reachable only when the server-side FeatureGate allows it
 *    (route middleware on every HTTP request, {@see canAccess()} on mount and on
 *    every Livewire hydrate);
 *  - protected actions re-check the same gate before doing any work.
 *
 * The page is hidden from navigation: visibility is never the authorization
 * boundary.
 */
class DemoShowcasePage extends Page
{
    public const MODULE_KEY = 'demo.showcase';

    public const PANEL_ID = PanelAccessPolicy::PANEL_CLINIC;

    protected static ?string $slug = 'demo-showcase';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|array $routeMiddleware = [
        EnsureModuleAccess::class.':'.self::MODULE_KEY,
    ];

    protected string $view = 'demomodule::pages.demo-showcase';

    public int $protectedActionsPerformed = 0;

    public static function canAccess(): bool
    {
        return app(ModuleGate::class)->allows(static::MODULE_KEY, static::PANEL_ID);
    }

    public function getTitle(): string|Htmlable
    {
        return __('demomodule::demomodule.page.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * A protected Livewire action: it re-evaluates the FeatureGate before doing
     * anything, so a revoked entitlement disables it immediately.
     */
    public function performProtectedAction(): void
    {
        app(ModuleGate::class)->authorize(static::MODULE_KEY, static::PANEL_ID);

        $this->protectedActionsPerformed++;
    }
}
