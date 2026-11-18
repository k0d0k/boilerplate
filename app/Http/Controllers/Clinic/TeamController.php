<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\BranchStaffAssigner;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Models\BranchStaffAssignment;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Tim & Cabang — TailAdmin branch team management (Phase 11).
 *
 * Replaces the "Kelola cabang & staf → Filament" escape hatch with a
 * first-class TailAdmin surface: the branches the identity may operate are
 * listed with their active staff count, and an authorized branch administrator
 * assigns or revokes staff on ONE branch from the branch page.
 *
 * Every grant/revoke goes through {@see BranchStaffAssigner}, which re-verifies
 * the acting identity's branch-administration role and the staff identity's
 * active central membership before writing to the tenant's isolated database.
 * A staff identity is a CENTRAL user, so candidates and names are resolved from
 * the central connection while the assignments themselves live in the tenant
 * database.
 */
final class TeamController extends Controller
{
    public function index(Request $request, ManagesBranchContext $branches): View
    {
        $user = $request->user();
        $ids = self::branchIds($branches, $user);
        abort_if($ids === [], 403);

        $locations = TenantBranch::query()
            ->whereIn('id', $ids)
            ->withCount([
                'staffAssignments as active_staff_count' => fn (Builder $query) => $query->where('status', BranchStaffAssignment::STATUS_ACTIVE),
            ])
            ->orderBy('name')
            ->get();

        return view('clinic.tailadmin.team', [
            'locations' => $locations,
            'canManage' => $branches->canManageBranches($user),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    /**
     * Staff management for ONE branch. Only a branch administrator may open it,
     * and only for a branch of the eligible (verified) set.
     */
    public function show(Request $request, string $branch, ManagesBranchContext $branches): View
    {
        $user = $request->user();
        $location = $this->accessibleBranch($branches, $user, $branch);

        $assignments = BranchStaffAssignment::query()
            ->where('branch_id', $location->getKey())
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();

        $assignedIds = $assignments->pluck('user_id')->all();

        return view('clinic.tailadmin.team-branch', [
            'location' => $location,
            'assignments' => $assignments,
            'staffNames' => $this->staffNames($assignedIds),
            'candidates' => $this->candidateStaff($user, $assignedIds),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function assign(Request $request, string $branch, ManagesBranchContext $branches): RedirectResponse
    {
        $user = $request->user();
        $this->accessibleBranch($branches, $user, $branch);

        $staff = $this->requestedStaff($request);

        try {
            app(BranchStaffAssigner::class)->assign($user, $staff, $branch);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['user_id' => $exception->getMessage()]);
        }

        return back()->with('success', __('lba.branches.staff.assigned'));
    }

    public function revoke(Request $request, string $branch, ManagesBranchContext $branches): RedirectResponse
    {
        $user = $request->user();
        $this->accessibleBranch($branches, $user, $branch);

        $staff = $this->requestedStaff($request);

        if (! app(BranchStaffAssigner::class)->revoke($user, $staff, $branch)) {
            return back()->withErrors(['user_id' => __('lba.branches.staff.not_assigned')]);
        }

        return back()->with('success', __('lba.branches.staff.revoked'));
    }

    /**
     * The requested staff identity, re-read from the CENTRAL database and
     * limited to a valid user id — the body is never trusted as authority.
     */
    private function requestedStaff(Request $request): User
    {
        $data = $request->validate(['user_id' => ['required', Rule::exists('users', 'id')]]);

        return User::query()->findOrFail($data['user_id']);
    }

    /**
     * Authorize the acting identity as a branch administrator and resolve the
     * branch from the eligible, verified set (a foreign or unknown branch is
     * refused — 403 / 404 — never widened).
     */
    private function accessibleBranch(ManagesBranchContext $branches, ?User $user, string $branch): TenantBranch
    {
        abort_unless($user instanceof User && $branches->canManageBranches($user), 403);

        $location = TenantBranch::query()->whereKey($branch)->firstOrFail();

        abort_unless(
            in_array((string) $location->getKey(), self::branchIds($branches, $user), true),
            403,
        );

        return $location;
    }

    /**
     * Names of the central users referenced by the given assignments.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function staffNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Active central members of the current tenant holding at least one role,
     * excluding identities already assigned to the branch.
     *
     * @param  list<string>  $excluded
     * @return array<string, string>
     */
    private function candidateStaff(User $user, array $excluded): array
    {
        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return [];
        }

        $members = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('roles')
            ->select('user_id');

        return User::query()
            ->where('is_active', true)
            ->whereIn('id', $members)
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('id', $excluded))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Ids of the branches the identity may operate in for the verified tenant.
     *
     * @return list<string>
     */
    private static function branchIds(ManagesBranchContext $branches, User $user): array
    {
        return array_map(static fn ($option): string => $option->id, $branches->eligibleOptions($user));
    }
}
