<?php

namespace App\Livewire;

use App\Filament\Principal\Pages\Dashboard;
use App\Models\User;
use App\Platform\Access\SupportTenantSession;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * WS9.E — persistent "open tenant" support banner.
 *
 * Rendered across the Principal panel while a bounded support context is active,
 * so the Super Admin always sees which tenant they are reviewing, when the
 * context expires, and can return with a single, CSRF-protected "Kembali ke
 * Principal" action. When no support context is active it renders nothing.
 */
class SupportTenantBanner extends Component
{
    public function close(): void
    {
        $actor = auth()->user();

        if ($actor instanceof User) {
            app(SupportTenantSession::class)->close($actor);
        }

        // Full navigation back to the Principal dashboard in the normal context.
        $this->redirect(Dashboard::getUrl(panel: 'principal'));
    }

    public function render(): View
    {
        $actor = auth()->user();
        $session = app(SupportTenantSession::class);

        $active = $actor instanceof User && $session->isActive($actor);

        return view('livewire.support-tenant-banner', [
            'active' => $active,
            'tenant' => $active ? $session->currentTenant($actor) : null,
            'expiresAt' => $active ? $session->expiresAt($actor) : null,
        ]);
    }
}
