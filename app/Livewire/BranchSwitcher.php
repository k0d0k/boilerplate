<?php

namespace App\Livewire;

use App\Filament\Clinic\Pages\Dashboard;
use App\Filament\Clinic\Resources\TenantBranchResource;
use App\Models\User;
use App\Platform\Branches\BranchOption;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Phase 2B — Clinic branch switcher.
 *
 * Sits next to the tenant switcher in the Clinic topbar and shows the VERIFIED
 * current branch. States:
 *   - exactly one accessible branch: a static identity chip (auto-selected);
 *   - several: a searchable listbox requiring an explicit choice;
 *   - none, but the identity may administer branches: a link to create the
 *     tenant's first branch ("setup"), so a freshly provisioned tenant is never
 *     deadlocked by the missing branch context;
 *   - none and no permission: nothing is rendered.
 *
 * The switch is authorized server-side in {@see switchTo()} against the
 * identity's accessible branches for the current tenant — a client cannot switch
 * into a revoked, suspended or foreign branch by forging the request.
 */
class BranchSwitcher extends Component
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

    public function switchTo(string $branchId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->is_active, 403);

        $manager = app(ManagesBranchContext::class);

        // Server-side authorization: verified tenant + active branch + access.
        abort_unless($manager->select($user, $branchId), 403);

        $this->open = false;
        $this->query = '';

        // Full navigation so the Clinic dashboard reloads in the new branch
        // context instead of morphing stale branch-scoped state.
        $this->redirect(Dashboard::getUrl(panel: 'clinic'));
    }

    public function render(): View
    {
        $user = auth()->user();
        $manager = app(ManagesBranchContext::class);

        /** @var list<BranchOption> $options */
        $options = $user instanceof User ? $manager->eligibleOptions($user) : [];
        $current = $user instanceof User ? $manager->currentBranch($user) : null;
        $canManage = $user instanceof User && $manager->canManageBranches($user);

        return view('livewire.branch-switcher', [
            'options' => $this->filterOptions($options),
            'totalOptions' => count($options),
            'current' => $current,
            'canManage' => $canManage,
            'setupUrl' => $canManage ? TenantBranchResource::getUrl('create', panel: 'clinic') : null,
        ]);
    }

    /**
     * @param  list<BranchOption>  $options
     * @return list<BranchOption>
     */
    private function filterOptions(array $options): array
    {
        $needle = trim(mb_strtolower($this->query));

        if ($needle === '') {
            return $options;
        }

        return array_values(array_filter(
            $options,
            fn (BranchOption $option) => str_contains(mb_strtolower($option->name), $needle)
                || str_contains(mb_strtolower($option->code ?? ''), $needle),
        ));
    }
}
