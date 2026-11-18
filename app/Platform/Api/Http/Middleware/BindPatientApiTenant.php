<?php

namespace App\Platform\Api\Http\Middleware;

use App\Models\User;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Api\PatientApiContext;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8B — binds the token's verified clinic database for one API request.
 *
 * Runs AFTER `auth:api`, so a valid token is guaranteed. The clinic comes from
 * the token (never from the URL or body) and the connection is released in
 * `finally`, so tenant data can never leak into the next request.
 *
 * The API surface is patient self-service only: an identity whose role does not
 * belong to the Patient panel is denied outright, mirroring the panel boundary.
 */
final class BindPatientApiTenant
{
    public function __construct(
        private readonly ResolvesTenantDatabase $databases,
        private readonly PatientApiContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        abort_unless(
            PanelAccessPolicy::roleCanAccessPanel($user->role, PanelAccessPolicy::PANEL_PATIENT),
            403,
        );

        $tenantId = $this->context->tenantId();

        abort_if($tenantId === null, 401);

        try {
            $this->databases->configure($tenantId);

            return $next($request);
        } finally {
            $this->databases->forget();
        }
    }
}
