<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\SaveLocationRequest;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lokasi Pemeriksaan — TailAdmin examination-location management (Phase 11).
 *
 * Replaces the "Kelola lokasi → Filament" escape hatch with a first-class
 * TailAdmin surface: the documented permanent, temporary and event locations
 * are created, edited and activated/deactivated here, against the verified
 * tenant's own isolated database (the {@see TenantBranch} model resolves that
 * connection and fails closed without it).
 *
 * Authorization is delegated to {@see ManagesBranchContext}: an identity that
 * may administer branches in the current tenant sees every location (including
 * deactivated ones, so it can reactivate them); any other identity sees only
 * the active locations it is assigned to, and is denied outright when it holds
 * none. Branches are never deleted — deactivation is non-destructive.
 */
final class LocationController extends Controller
{
    public function index(Request $request, ManagesBranchContext $branches): View
    {
        $user = $request->user();
        $canManage = $branches->canManageBranches($user);

        if ($canManage) {
            // A manager may administer every location of the tenant, including
            // deactivated ones, so the list is not filtered to active rows.
            $locations = TenantBranch::query()->orderBy('name')->get();
        } else {
            $ids = array_column($branches->eligibleOptions($user), 'id');
            abort_if($ids === [], 403);

            $locations = TenantBranch::query()->whereIn('id', $ids)->orderBy('name')->get();
        }

        return view('clinic.tailadmin.locations', [
            'locations' => $locations,
            'canManage' => $canManage,
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function create(Request $request, ManagesBranchContext $branches): View
    {
        $this->authorizeManagement($branches);

        return view('clinic.tailadmin.location-form', [
            'location' => null,
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function store(SaveLocationRequest $request): RedirectResponse
    {
        TenantBranch::query()->create($request->validated());

        return to_route('clinic.tailadmin.locations')->with('success', __('lba.branches.created'));
    }

    public function edit(Request $request, string $location, ManagesBranchContext $branches): View
    {
        $this->authorizeManagement($branches);

        return view('clinic.tailadmin.location-form', [
            'location' => $this->resolveLocation($location),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function update(SaveLocationRequest $request, string $location): RedirectResponse
    {
        $this->resolveLocation($location)->fill($request->validated())->save();

        return to_route('clinic.tailadmin.locations')->with('success', __('lba.branches.updated'));
    }

    public function activate(Request $request, string $location, ManagesBranchContext $branches): RedirectResponse
    {
        $this->authorizeManagement($branches);

        $this->resolveLocation($location)->activate();

        return to_route('clinic.tailadmin.locations')->with('success', __('lba.branches.activated'));
    }

    public function deactivate(Request $request, string $location, ManagesBranchContext $branches): RedirectResponse
    {
        $this->authorizeManagement($branches);

        $this->resolveLocation($location)->deactivate();

        return to_route('clinic.tailadmin.locations')->with('success', __('lba.branches.deactivated'));
    }

    /**
     * Resolve the target branch from the VERIFIED tenant database.
     *
     * Implicit route-model binding cannot be used here: `SubstituteBindings`
     * runs before the tenant connection is bound, so a `TenantBranch` would
     * fail closed. The id is resolved explicitly inside the request instead —
     * a foreign or unknown id is simply not found (404), never widened.
     */
    private function resolveLocation(string $location): TenantBranch
    {
        return TenantBranch::query()->whereKey($location)->firstOrFail();
    }

    private function authorizeManagement(ManagesBranchContext $branches): void
    {
        abort_unless($branches->canManageBranches(request()->user()), 403);
    }
}
