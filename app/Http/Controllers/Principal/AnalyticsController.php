<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Http\Controllers\Controller;
use App\Platform\Access\PermissionKey;
use App\Platform\Analytics\PlatformAnalytics;
use Illuminate\Contracts\View\View;

/**
 * WS4.3 — Principal "Analitik Bisnis" TailAdmin surface.
 *
 * Renders inside the shared TailAdmin shell (the same shell the dashboard and
 * every other governance screen use) the non-clinical platform figures computed
 * by {@see PlatformAnalytics} from central governance tables only. It never
 * opens a tenant clinical connection, so no patient, examination, image or
 * finding is reachable here.
 *
 * Authorization is re-derived on the SERVER: viewing requires
 * `platform.analytics.view`; the surface performs no mutations.
 */
final class AnalyticsController extends Controller
{
    use AuthorizesPrincipalResources;

    public function index(PlatformAnalytics $analytics): View
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_ANALYTICS_VIEW), 403);

        return view('principal.analytics.index', [
            'partners' => $analytics->partners(),
            'databases' => $analytics->tenantDatabases(),
            'subscriptions' => $analytics->subscriptions(),
            'credits' => $analytics->aiCredits(),
            'mrr' => $analytics->monthlyRecurringRevenue(),
            'plans' => $analytics->revenueByPlan(),
        ]);
    }
}
