<?php

namespace App\Filament\Patient\Middleware;

use App\Filament\Patient\Pages\SelectTenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8A — server-side patient-panel context guard.
 *
 * Mirrors the Clinic tenant guard: applied to the patient panel's authenticated
 * routes (and replayed on Livewire update requests via the panel's persistent
 * middleware) so the gateway is enforced on the SERVER, never by hiding
 * navigation:
 *
 *  - a verified context (single membership auto-selected, or a prior explicit
 *    choice) passes straight through;
 *  - several eligible memberships with nothing selected is redirected to the
 *    "Pilih Klinik" screen — no tenant is ever chosen arbitrarily;
 *  - no eligible membership is denied outright: a patient identity is always
 *    scoped to a partner clinic through an active membership.
 *
 * The selection screen and the logout route are exempt so the patient can
 * choose a clinic or sign out.
 */
class EnsurePatientTenantContext
{
    public function __construct(private readonly ManagesTenantContext $context) {}

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
            return $next($request);
        }

        if ($this->context->hasMultipleEligibleMemberships($user)) {
            return redirect()->to(SelectTenant::getUrl(panel: 'patient'));
        }

        abort(403, __('lba.patient_guard.no_active_clinic'));
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        return in_array($name, [
            SelectTenant::getRouteName(Filament::getPanel('patient')),
            'filament.patient.auth.logout',
        ], true);
    }
}
