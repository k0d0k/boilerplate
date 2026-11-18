<?php

namespace App\Platform\FeatureGate\Middleware;

use App\Platform\FeatureGate\ModuleGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware enforcing the FeatureGate on a module route (Phase 1B-4).
 *
 * A module page attaches it to its own route (via Filament's page
 * `$routeMiddleware`) so the decision is made server-side on every request,
 * including replayed Livewire requests — never by hiding navigation.
 *
 * Usage: `EnsureModuleAccess::class.':demo.showcase'`.
 */
class EnsureModuleAccess
{
    public function __construct(private readonly ModuleGate $gate) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(
            $this->gate->allows($module),
            403,
            __('lba.module.access_denied'),
        );

        return $next($request);
    }
}
