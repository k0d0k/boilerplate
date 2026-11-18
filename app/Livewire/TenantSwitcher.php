<?php

namespace App\Livewire;

use App\Filament\Clinic\Pages\Dashboard;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\TenantOption;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Phase 1B-2B — premium Clinic tenant switcher.
 *
 * Shows the verified current tenant (name + brand label) in the Clinic topbar
 * and, for identities with more than one eligible membership, an elegant
 * searchable dropdown. Identities with exactly one membership see a static
 * identity chip — no choice is required.
 *
 * This component renders only *real* authorized memberships resolved from the
 * central tables; it never invents tenants. The switch itself is authorized
 * server-side in {@see switchTo()}: Livewire supplies CSRF protection, and the
 * requested tenant id is validated against the identity's active membership
 * before it is stored — a client cannot switch into a foreign or revoked
 * tenant by forging the request.
 */
class TenantSwitcher extends Component
{
    public bool $open = false;

    public string $query = '';

    public function toggle(): void
    {
        $this->open = ! $this->open;
        $this->query = '';
    }

    public function close(): void
    {
        $this->open = false;
        $this->query = '';
    }

    public function switchTo(string $tenantId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->is_active, 403);

        $manager = app(ManagesTenantContext::class);

        // Server-side authorization: well-formed ULID + active membership +
        // active tenant + active identity, otherwise 403 with no detail leaked.
        abort_unless($manager->select($user, $tenantId), 403);

        $this->open = false;
        $this->query = '';

        // Full navigation so the Clinic dashboard reloads in the new context
        // instead of morphing stale tenant-scoped state.
        $this->redirect(Dashboard::getUrl(panel: 'clinic'));
    }

    public function render(): View
    {
        $user = auth()->user();
        $manager = app(ManagesTenantContext::class);

        /** @var list<TenantOption> $options */
        $options = $user instanceof User ? $manager->eligibleOptions($user) : [];
        $current = $user instanceof User ? $manager->currentTenant($user) : null;

        return view('livewire.tenant-switcher', [
            'options' => $this->filterOptions($options),
            'totalOptions' => count($options),
            'current' => $current,
            'currentBrand' => $this->brandFor($options, $current),
        ]);
    }

    /**
     * @param  list<TenantOption>  $options
     * @return list<TenantOption>
     */
    private function filterOptions(array $options): array
    {
        $needle = trim(mb_strtolower($this->query));

        if ($needle === '') {
            return $options;
        }

        return array_values(array_filter(
            $options,
            fn (TenantOption $option) => str_contains(mb_strtolower($option->name), $needle)
                || str_contains(mb_strtolower($option->brandLabel ?? ''), $needle),
        ));
    }

    /**
     * @param  list<TenantOption>  $options
     */
    private function brandFor(array $options, ?Tenant $current): ?string
    {
        if ($current === null) {
            return null;
        }

        foreach ($options as $option) {
            if ($option->id === $current->getKey()) {
                return $option->brandLabel;
            }
        }

        return null;
    }
}
