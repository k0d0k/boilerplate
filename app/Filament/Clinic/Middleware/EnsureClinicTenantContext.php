<?php

namespace App\Filament\Clinic\Middleware;

use App\Filament\Clinic\Pages\SelectTenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Tenancy\TenantDatabaseReadiness;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 1B-2B — server-side Clinic tenant context guard.
 *
 * Applied to the Clinic panel's authenticated routes (including Livewire
 * replayed persistent middleware) so the gateway is enforced at the server
 * layer, never by hiding navigation:
 *
 *  - a verified context (single membership auto-selected, or a prior explicit
 *    choice) passes straight through;
 *  - several eligible memberships with nothing selected is redirected to the
 *    "Pilih Mitra" screen — no tenant is ever chosen arbitrarily;
 *  - no eligible membership is denied outright.
 *
 * Transition note (STEP 5): `users.role` still routes an identity to the Clinic
 * panel (approving it, unchanged, so login keeps working), but it no longer
 * grants *operational* tenant context on its own. A Clinic role without an
 * active membership reaches the panel shell but is denied the dashboard.
 *
 * The selection screen and the logout route are exempt so the user can choose a
 * tenant or sign out. When a verified context exists but the tenant's isolated
 * database is not ready (provisioned-but-not-migrated, failed or dropped), the
 * request is denied with a useful message rather than failing mid-query.
 */
class EnsureClinicTenantContext
{
    public function __construct(
        private readonly ManagesTenantContext $context,
        private readonly TenantDatabaseReadiness $readiness,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Guests are resolved by Filament's Authenticate middleware.
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        if ($this->context->hasVerifiedContext($user)) {
            $tenantId = $this->context->currentTenantId($user);

            if ($tenantId !== null && ! $this->readiness->isReady($tenantId)) {
                abort(503, __('lba.tenant_guard.database_unavailable'));
            }

            return $next($request);
        }

        if ($this->context->hasMultipleEligibleMemberships($user)) {
            return redirect()->to(SelectTenant::getUrl(panel: 'clinic'));
        }

        abort(403, __('lba.tenant_guard.no_active_partner'));
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        return in_array($name, [
            SelectTenant::getRouteName(Filament::getPanel('clinic')),
            'filament.clinic.auth.logout',
        ], true);
    }
}
