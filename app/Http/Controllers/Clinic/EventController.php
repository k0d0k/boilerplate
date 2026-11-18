<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Appointments\Services\BranchAvailability;
use Modules\Patients\Models\Patient;

/**
 * Acara & Pemesanan — TailAdmin events, schedule, capacity and patient booking
 * (Phase 11).
 *
 * This is the TailAdmin counterpart of the appointment/event book. It wires the
 * already-implemented scheduling domain to a first-class surface instead of a
 * bare list:
 *
 *  - the eligible-branch book, with an optional `location` filter that can only
 *    NARROW the eligible set (a foreign location is refused, never widened);
 *  - per-location detail carrying the day's bookable slots and their remaining
 *    capacity projection ({@see BranchAvailability}, a pure read);
 *  - a patient booking action that delegates every mutation to the existing
 *    {@see AppointmentScheduler}, so the operating window, capacity and
 *    double-booking guards stay the single authority;
 *  - tentative (unconfirmed temporary / event) sites are shown clearly as
 *    preregistration and publish no slot: a booking against one is refused
 *    rather than falsely confirmed.
 *
 * Authorization is delegated to {@see ManagesBranchContext}: a branch manager
 * may open every location of the verified tenant (including tentative events,
 * to confirm or book them); any other identity only sees the active branches it
 * is assigned to and is denied when it is assigned to none.
 */
final class EventController extends Controller
{
    public function index(Request $request, ManagesBranchContext $branches): View
    {
        abort_unless(AppointmentResource::canViewAny(), 403);

        $user = $request->user();
        $canManage = $branches->canManageBranches($user);
        $ids = self::branchIds($branches, $user);

        // A non-manager needs at least one eligible branch; a manager may still
        // open the page with only tentative locations to confirm.
        abort_if($ids === [] && ! $canManage, 403);

        $filters = $request->validate(['location' => ['nullable', 'ulid']]);
        $location = $filters['location'] ?? '';
        abort_unless($location === '' || in_array($location, $ids, true), 403);

        // A manager sees every location (tentative ones included); anyone else
        // only the active set they are assigned to.
        $locations = $canManage
            ? TenantBranch::query()->orderBy('name')->get()
            : TenantBranch::query()->whereIn('id', $ids)->orderBy('name')->get();

        $bookings = Appointment::query()
            ->with(['patient', 'branch'])
            ->whereIn('branch_id', $ids)
            ->when($location !== '', fn (Builder $query) => $query->where('branch_id', $location))
            ->orderByDesc('starts_at')->orderByDesc('id')->paginate(15)->appends($filters);

        return view('clinic.tailadmin.events', [
            'location' => $location,
            'locations' => $locations,
            'eventLocations' => $locations
                ->whereIn('location_type', [TenantBranch::LOCATION_TEMPORARY, TenantBranch::LOCATION_EVENT])
                ->values(),
            'bookings' => $bookings,
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function show(Request $request, string $location, ManagesBranchContext $branches): View
    {
        abort_unless(AppointmentResource::canViewAny(), 403);

        $resolved = $this->accessibleLocation($branches, $request->user(), $location);

        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $filters['date'] ?? self::defaultDate($resolved);

        // A tentative site publishes no slot at all; a confirmed one publishes
        // the day's slots with their remaining capacity projection.
        $slots = $resolved->isTentative()
            ? []
            : app(BranchAvailability::class)->slotsForDate($resolved, Carbon::parse($date));

        $bookings = Appointment::query()
            ->with('patient')
            ->where('branch_id', $resolved->getKey())
            ->orderBy('starts_at')->orderBy('id')->paginate(15);

        return view('clinic.tailadmin.events-show', [
            'location' => $resolved,
            'date' => $date,
            'slots' => $slots,
            'bookings' => $bookings,
            'canBook' => AppointmentResource::canCreate() && ! $resolved->isTentative(),
            'patients' => Patient::query()->active()
                ->orderBy('full_name')->orderBy('id')->limit(50)
                ->get(['id', 'full_name', 'patient_number']),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function store(Request $request, string $location, ManagesBranchContext $branches, AppointmentScheduler $appointments): RedirectResponse
    {
        abort_unless(AppointmentResource::canCreate(), 403);

        $resolved = $this->accessibleLocation($branches, $request->user(), $location);

        // A tentative site is preregistration only: refuse to confirm a booking.
        abort_if($resolved->isTentative(), 403);

        $data = $request->validate([
            'patient_id' => ['required', 'ulid', Rule::exists(Patient::class, 'id')->where('status', Patient::STATUS_ACTIVE)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'service_type' => ['required', Rule::in(Appointment::SERVICES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $appointments->create($data + ['branch_id' => $resolved->getKey()], $request->user());
        } catch (AppointmentConflictException $exception) {
            throw ValidationException::withMessages(['starts_at' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.events.show', $resolved->getKey())
            ->with('success', __('lba.events.booked'));
    }

    /**
     * Resolve the target location from the VERIFIED tenant database and prove
     * the identity may operate it. Implicit route-model binding cannot be used:
     * `SubstituteBindings` runs before the tenant connection is bound, so a
     * `TenantBranch` would fail closed. A foreign or unknown id is simply not
     * found (404); an ineligible one is forbidden (403), never widened.
     */
    private function accessibleLocation(ManagesBranchContext $branches, ?User $user, string $location): TenantBranch
    {
        abort_unless($user instanceof User, 403);

        if ($branches->canManageBranches($user)) {
            return TenantBranch::query()->whereKey($location)->firstOrFail();
        }

        $ids = self::branchIds($branches, $user);
        abort_if($ids === [], 403);

        $resolved = TenantBranch::query()->whereKey($location)->firstOrFail();
        abort_unless(in_array((string) $resolved->getKey(), $ids, true), 403);

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private static function branchIds(ManagesBranchContext $branches, User $user): array
    {
        return array_map(static fn ($option): string => $option->id, $branches->eligibleOptions($user));
    }

    /**
     * The day shown by default: today, or the first day of a temporary / event
     * site's window when that is still in the future (so a future event opens on
     * its own schedule rather than an empty today).
     */
    private static function defaultDate(TenantBranch $location): string
    {
        $today = now()->startOfDay();

        if (! $location->isPermanent()
            && $location->operates_from !== null
            && $location->operates_from->greaterThan($today)) {
            return $location->operates_from->toDateString();
        }

        return $today->toDateString();
    }
}
