<?php

namespace App\Platform\Tenancy\Http\Middleware;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the isolated tenant database for the duration of one request
 * (Phase 2A).
 *
 * Only a VERIFIED tenant context is honoured — the id comes from the existing
 * {@see ManagesTenantContext}, which re-derives eligibility from the central
 * membership tables on every call. With no verified context (guest, no
 * membership, suspended tenant) nothing is bound and any stale connection is
 * dropped.
 *
 * The connection is released in a `finally` block, so the tenant database can
 * never leak into a subsequent request, Livewire update or panel render.
 */
final class InitializeTenantDatabase
{
    public function __construct(
        private readonly ResolvesTenantDatabase $databases,
        private readonly ManagesTenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            $this->databases->forget();

            return $next($request);
        }

        $tenantId = $this->context->currentTenantId($user);

        if ($tenantId === null) {
            $this->databases->forget();

            return $next($request);
        }

        try {
            $this->databases->configure($tenantId);

            return $next($request);
        } finally {
            $this->databases->forget();
        }
    }
}
