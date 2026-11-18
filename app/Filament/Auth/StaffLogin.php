<?php

namespace App\Filament\Auth;

use App\Platform\Access\PanelResolver;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The ONE unified staff authentication entry point (`/login`).
 *
 * Principal administrators, brand managers, tenant owners/admins, clinic
 * employees and authorised professionals all sign in here with the same
 * email + password form. Presentation is inherited unchanged from
 * {@see Login}; only the panel resolution differs:
 *
 *  - the permitted panel is resolved server-side from the identity (never from
 *    a request parameter, query string or form field);
 *  - only the staff panels (Principal, Clinic) are accepted, so a patient
 *    identity can never sign in through this entry point;
 *  - unknown roles and suspended accounts are denied by the same
 *    `canAccessPanel()` gate the panels enforce on every request.
 *
 * All Filament authentication behaviour (rate limiting, constant-time failure,
 * multi-factor handling, session regeneration) is inherited from the base page.
 */
class StaffLogin extends Login
{
    public function mount(): void
    {
        if (Filament::auth()->check()) {
            // An already-authenticated identity never sees the login form
            // again. It is sent to the panel it is authorised to enter, and
            // identities with no accessible panel (unknown role, suspended
            // account) fail closed to the neutral public entry point instead of
            // any panel URL.
            //
            // The redirect is performed through the container (`redirect()`),
            // not by returning a response: inside a Livewire mount Livewire's
            // SupportRedirects swaps in a component-aware redirector and emits
            // the resulting redirect itself (aborting with it on the initial,
            // non-Livewire page request). This mirrors Filament's own
            // `Login::mount()`, which likewise discards the return value.
            redirect()->to($this->panelUrl(app(PanelResolver::class)->panelPathFor(Filament::auth()->user())));

            return;
        }

        $this->form->fill();
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response === null) {
            return null;
        }

        return app(StaffLoginResponse::class);
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return app(PanelResolver::class)->staffPanelPathFor($user) !== null;
    }

    private function panelUrl(?string $panelPath): string
    {
        return '/'.($panelPath ?? '');
    }
}
