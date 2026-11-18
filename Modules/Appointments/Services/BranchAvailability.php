<?php

namespace Modules\Appointments\Services;

use App\Platform\Branches\Models\TenantBranch;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Appointments\Models\Appointment;

/**
 * Phase 10 (WS1) — branch / event availability.
 *
 * Computes the bookable slots of ONE branch on ONE day from the branch's own
 * scheduling rules ({@see TenantBranch::openingWindow()},
 * {@see TenantBranch::effectiveSlotMinutes()}) and the appointments that already
 * occupy a slot. It is a pure READ: it never writes and never bypasses the
 * booking guards in {@see AppointmentScheduler}, which remain the only writer.
 *
 * A branch with a `capacity` accepts up to that many OVERLAPPING occupying
 * appointments per slot (an event venue with several parallel stations); a
 * branch without one is unlimited, preserving the historical behaviour.
 */
final class BranchAvailability
{
    /**
     * The slots of a branch for a given day.
     *
     * @return list<array{
     *     starts_at: string,
     *     ends_at: string,
     *     available: bool,
     *     remaining: int|null,
     *     capacity: int|null
     * }>
     */
    public function slotsForDate(TenantBranch $branch, CarbonInterface $date): array
    {
        if (! $branch->isBookableOn($date)) {
            return [];
        }

        [$opens, $closes] = $branch->openingWindow($date);

        if ($closes->lessThanOrEqualTo($opens)) {
            return [];
        }

        $step = $branch->effectiveSlotMinutes();
        $capacity = $branch->effectiveCapacity();

        $occupied = Appointment::query()
            ->where('branch_id', $branch->getKey())
            ->whereIn('status', Appointment::OCCUPYING_STATUSES)
            ->where('starts_at', '<', $closes)
            ->where('ends_at', '>', $opens)
            ->get(['starts_at', 'ends_at']);

        $now = Carbon::now($branch->timezone ?: config('app.timezone'));

        $slots = [];

        for ($start = $opens->copy(); $start->lessThan($closes); $start->addMinutes($step)) {
            $end = $start->copy()->addMinutes($step);

            if ($end->greaterThan($closes)) {
                break;
            }

            $booked = $occupied->filter(
                fn (Appointment $appointment): bool => $appointment->starts_at < $end
                    && $appointment->ends_at > $start,
            )->count();

            $remaining = $capacity === null ? null : max(0, $capacity - $booked);

            $slots[] = [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'available' => $start->greaterThan($now) && ($capacity === null || $remaining > 0),
                'remaining' => $remaining,
                'capacity' => $capacity,
            ];
        }

        return $slots;
    }
}
