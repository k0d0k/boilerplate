<?php

namespace App\Filament\Clinic\Pages;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\TenantOption;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 1B-2B — "Pilih Mitra" explicit tenant selection screen.
 *
 * Reached only when the authenticated identity holds more than one eligible
 * membership and no verified selection yet (the Clinic context guard redirects
 * here). It is exempt from the guard so it can render, but it is still
 * server-gated: an identity with no eligible membership is denied outright, and
 * a verified selection sends the user straight back to the dashboard.
 *
 * The page is hidden from navigation — it is a security step, not a module.
 */
class SelectTenant extends Page
{
    protected static ?string $slug = 'pilih-mitra';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.clinic.pages.select-tenant';

    public function getTitle(): string|Htmlable
    {
        return __('lba.select_tenant.page_title');
    }

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->is_active, 403);

        $manager = app(ManagesTenantContext::class);

        // No eligible membership -> the Clinic panel has no operational context
        // for this identity.
        abort_unless($manager->hasEligibleMembership($user), 403);

        // Already resolved (single membership auto-selected, or a prior choice):
        // there is nothing to select.
        if ($manager->hasVerifiedContext($user)) {
            $this->redirect(Dashboard::getUrl(panel: 'clinic'));
        }
    }

    public function selectTenant(string $tenantId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->is_active, 403);

        $manager = app(ManagesTenantContext::class);

        // Same server-side authorization as the topbar switcher.
        abort_unless($manager->select($user, $tenantId), 403);

        $this->redirect(Dashboard::getUrl(panel: 'clinic'));
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return list<TenantOption>
     */
    public function getTenantOptions(): array
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(ManagesTenantContext::class)->eligibleOptions($user)
            : [];
    }
}
