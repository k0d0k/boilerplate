<?php

namespace App\Providers;

use App\Platform\Api\PatientApiContext;
use App\Platform\Api\PatientApiTokenAuthenticator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 8B — patient REST API wiring.
 *
 * Registers the bearer-token guard driver, the request-scoped token context and
 * the API rate limiters. Authorization itself is NOT duplicated here: the API
 * reuses the platform's permission and tenant-isolation services.
 */
final class PatientApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A fresh context per request; it can never leak across callers.
        $this->app->scoped(PatientApiContext::class, fn (): PatientApiContext => new PatientApiContext);
    }

    public function boot(): void
    {
        Auth::viaRequest('patient-token', function (Request $request): mixed {
            return $this->app->make(PatientApiTokenAuthenticator::class)->retrieve($request);
        });

        // Named limiters. `api` backs the default framework API group; the two
        // patient limiters tighten the authenticated surface and brute-force
        // protect the login endpoint.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($this->key($request)));

        RateLimiter::for('patient-api', fn (Request $request) => Limit::perMinute(120)
            ->by($this->key($request)));

        RateLimiter::for('patient-api-login', fn (Request $request) => Limit::perMinute(5)
            ->by('login:'.$request->ip()));
    }

    private function key(Request $request): string
    {
        $id = $request->user('api')?->getAuthIdentifier();

        return $id !== null ? 'patient:'.$id : 'ip:'.$request->ip();
    }
}
