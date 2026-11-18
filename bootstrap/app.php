<?php

use App\Localization\Middleware\SetLocale;
use App\Platform\Api\Http\Middleware\BindPatientApiTenant;
use App\Platform\Api\Support\ApiExceptionRenderer;
use App\Platform\Tenancy\Http\Middleware\InitializeTenantDatabase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\LBA\Http\Middleware\AuthenticateLbaIngest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Phase 8B — the patient REST API (prefix `api`, `api` middleware group).
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Phase 1B-3: resolve and apply the active locale for the `web` group,
        // which also covers Livewire's update endpoint (its requests run
        // through this group). Panel routes add the same middleware to their
        // own stack so regular page loads resolve the locale too.
        //
        // Phase 3: `InitializeTenantDatabase` is appended here for the same
        // reason. A tenant-backed Filament resource must query with the verified
        // tenant database bound, and Livewire re-renders the component on the
        // update endpoint — which is outside the panel route stack. Binding it
        // here wraps the WHOLE update request (hydrate + render), whereas
        // Livewire's persistent-middleware replay only re-runs middleware
        // inside a sub-pipeline and would release the connection before render.
        // Panel page loads bind it from the panel's own middleware stack.
        $middleware->web(append: [
            SetLocale::class,
            InitializeTenantDatabase::class,
        ]);

        // Phase 8B — the patient API's tenant binder (name is alias-safe so the
        // API routes can opt in without touching the `api` group).
        $middleware->alias([
            'patient.api' => BindPatientApiTenant::class,
            // WS6.B — scoped agent ingest authentication (no session, no CSRF:
            // the agent presents a short-lived, tenant-scoped bearer credential).
            'lba.ingest' => AuthenticateLbaIngest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // NEVER flash a write-only credential back to the session. On a
        // validation failure the framework flashes the old input so the form can
        // refill; the cPanel API token and the per-tenant MySQL password are
        // write-only and must never land in the session store (or a stale
        // response) when the form is re-rendered.
        $exceptions->dontFlash(['token', 'db_password']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Phase 8B — a single JSON error envelope for every `api/*` failure.
        $exceptions->render(
            fn (Throwable $exception, Request $request) => app(ApiExceptionRenderer::class)->render($exception, $request),
        );
    })->create();
