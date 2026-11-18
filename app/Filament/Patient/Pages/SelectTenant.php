<?php

namespace App\Filament\Patient\Pages;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\TenantOption;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 8A — "Pilih Klinik" explicit clinic selection screen.
 *
 * Reached only when a patient identity holds more than one eligible membership
 * and no verified selection yet (the patient context guard redirects here). It
 * is exempt from the guard so it can render, but remains server-gated: an
 * identity with no eligible membership is denied outright, and an existing
 * verified selection sends the patient straight back to the dashboard.
 *
 * The page is hidden from navigation — it is a security step, not a module.
 */
class SelectTenant extends Page
{
    protected static ?string $slug = 'pilih-klinik';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.patient.pages.select-tenant';

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient_select.page_title');
    }

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->is_active, 403);

        $manager = app(ManagesTenantContext::class);

        // No eligible membership -> the patient panel has no clinical context
        // for this identity.
        abort_unless($manager->hasEligibleMembership($user), 403);

        if ($manager->hasVerifiedContext($user)) {
            $this->redirect(Dashboard::getUrl(panel: 'patient'));
        }
    }

    public function selectTenant(string $tenantId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->is_active, 403);

        $manager = app(ManagesTenantContext::class);

        // Same server-side authorization as any other context switch.
        abort_unless($manager->select($user, $tenantId), 403);

        $this->redirect(Dashboard::getUrl(panel: 'patient'));
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
