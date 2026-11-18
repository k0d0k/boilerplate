<?php

namespace Modules\Practitioners\Services;

use App\Platform\Branches\Models\TenantBranch;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Appointments\Models\Appointment;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Models\PractitionerSchedule;
use Modules\Practitioners\Models\PractitionerScheduleException;

/**
 * WS7.B — practitioner availability (pure READ).
 *
 * Computes the bookable slots of ONE practitioner at ONE branch on ONE day from
 * that practitioner's OWN practice schedule (weekly windows + dated exceptions),
 * intersected with the branch's operating window and minus the appointments that
 * already occupy the practitioner. It never writes and never bypasses the
 * booking guards in the appointments layer, which remain the only writer.
 *
 * The practitioner is only offered while VERIFIED, active, still a clinical
 * member of the verified tenant and explicitly AUTHORIZED for the branch — the
 * same gate the booking chokepoint re-derives. Only scheduling data is exposed;
 * personal employee details, contact information, licences and clinical notes
 * are never surfaced.
 */
final class PractitionerAvailability
{
    public function __construct(
        private readonly PractitionerRegistry $registry,
    ) {}

    /**
     * Verified practitioners who may take bookings at the branch (optionally for
     * one service). A practitioner with no window matching the service is still
     * listed — availability is resolved per date.
     *
     * @return Collection<int, Practitioner>
     */
    public function bookablePractitioners(TenantBranch $branch, ?string $serviceType = null): Collection
    {
        return Practitioner::query()
            ->verified()
            ->orderBy('display_name')
            ->get()
            ->filter(fn (Practitioner $practitioner): bool => $this->registry->isEligibleForBooking($practitioner)
                && $this->registry->isAuthorizedForBranch($practitioner, (string) $branch->getKey()))
            ->values();
    }

    /**
     * The bookable slots of one practitioner at one branch on one day.
     *
     * @return list<array{
     *     starts_at: string,
     *     ends_at: string,
     *     available: bool,
     *     remaining: int|null,
     *     capacity: int|null,
     *     practitioner_id: string
     * }>
     */
    public function slotsForDate(
        Practitioner $practitioner,
        TenantBranch $branch,
        CarbonInterface $date,
        ?string $serviceType = null,
    ): array {
        if (! $this->offersAt($practitioner, $branch)) {
            return [];
        }

        if (! $branch->isBookableOn($date)) {
            return [];
        }

        $timezone = $branch->timezone ?: (string) config('app.timezone');
        $day = Carbon::parse($date)->setTimezone($timezone);

        $windows = $this->windowIntervals($practitioner, $branch, $day, $serviceType);

        if ($windows === []) {
            return [];
        }

        $blocked = $this->blockedIntervals($practitioner, $branch, $day);

        $capacity = $branch->effectiveCapacity();
        $branchAppointments = $this->occupyingAppointments($branch, $day);
        $staffUserId = (string) $practitioner->user_id;
        $now = Carbon::now($timezone);

        $slots = [];

        foreach ($windows as [$opens, $closes, $step]) {
            for ($start = $opens->copy(); $start->lessThan($closes); $start->addMinutes($step)) {
                $end = $start->copy()->addMinutes($step);

                if ($end->greaterThan($closes)) {
                    break;
                }

                if ($this->isBlocked($blocked, $start, $end)) {
                    continue;
                }

                $practitionerBusy = $branchAppointments->contains(
                    fn (Appointment $appointment): bool => (string) $appointment->staff_user_id === $staffUserId
                        && $appointment->starts_at < $end
                        && $appointment->ends_at > $start,
                );

                $branchOverlap = $branchAppointments->filter(
                    fn (Appointment $appointment): bool => $appointment->starts_at < $end
                        && $appointment->ends_at > $start,
                )->count();

                $remaining = $capacity === null ? null : max(0, $capacity - $branchOverlap);
                $withinCapacity = $capacity === null || $remaining > 0;

                $slots[] = [
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                    'available' => ! $practitionerBusy && $withinCapacity && $start->greaterThan($now),
                    'remaining' => $remaining,
                    'capacity' => $capacity,
                    'practitioner_id' => (string) $practitioner->getKey(),
                ];
            }
        }

        return $slots;
    }

    /**
     * True when the practitioner's own schedule allows the [start, end) instant
     * range at the branch (subtractive exceptions honoured). Used by the booking
     * chokepoint as a server-side re-check; it does NOT check booking overlaps.
     */
    public function covers(
        Practitioner $practitioner,
        TenantBranch $branch,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $serviceType = null,
    ): bool {
        if (! $this->offersAt($practitioner, $branch)) {
            return false;
        }

        $timezone = $branch->timezone ?: (string) config('app.timezone');
        $day = Carbon::parse($start)->setTimezone($timezone);

        if (! $branch->isBookableOn($day)) {
            return false;
        }

        $blocked = $this->blockedIntervals($practitioner, $branch, $day);

        if ($this->isBlocked($blocked, $start, $end)) {
            return false;
        }

        foreach ($this->windowIntervals($practitioner, $branch, $day, $serviceType) as [$opens, $closes]) {
            if ($start->greaterThanOrEqualTo($opens) && $end->lessThanOrEqualTo($closes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The practitioner's slot length at the branch on the given day (schedule
     * override, falling back to the branch default). Returns null when no window
     * covers the moment.
     */
    public function slotMinutesAt(
        Practitioner $practitioner,
        TenantBranch $branch,
        CarbonInterface $start,
        ?string $serviceType = null,
    ): ?int {
        $timezone = $branch->timezone ?: (string) config('app.timezone');
        $day = Carbon::parse($start)->setTimezone($timezone);

        foreach ($this->windowIntervals($practitioner, $branch, $day, $serviceType) as [$opens, $closes, $step]) {
            if ($start->greaterThanOrEqualTo($opens) && $start->lessThan($closes)) {
                return $step;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function offersAt(Practitioner $practitioner, TenantBranch $branch): bool
    {
        return $this->registry->isEligibleForBooking($practitioner)
            && $this->registry->isAuthorizedForBranch($practitioner, (string) $branch->getKey());
    }

    /**
     * The merged, sorted, branch-clipped bookable windows for the day.
     *
     * @return list<array{0: Carbon, 1: Carbon, 2: int}>
     */
    private function windowIntervals(
        Practitioner $practitioner,
        TenantBranch $branch,
        CarbonInterface $day,
        ?string $serviceType,
    ): array {
        $timezone = $branch->timezone ?: (string) config('app.timezone');
        [$branchOpens, $branchCloses] = $branch->openingWindow($day);

        $defaultStep = $branch->effectiveSlotMinutes();
        $raw = [];

        $schedules = PractitionerSchedule::query()
            ->where('practitioner_id', $practitioner->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('status', PractitionerSchedule::STATUS_ACTIVE)
            ->get();

        foreach ($schedules as $schedule) {
            if (! $schedule->coversDate($day) || ! $schedule->appliesToService($serviceType)) {
                continue;
            }

            [$opens, $closes] = $schedule->windowFor($day, $timezone);
            $raw[] = [$opens, $closes, $schedule->slotMinutes($defaultStep)];
        }

        $additive = $this->exceptionsForDay($practitioner, $branch, $day)
            ->filter(fn (PractitionerScheduleException $exception): bool => $exception->isAdditive());

        foreach ($additive as $exception) {
            [$opens, $closes] = $exception->resolvedRange($timezone);
            $raw[] = [$opens, $closes, $defaultStep];
        }

        $intervals = [];

        foreach ($raw as [$opens, $closes, $step]) {
            $opens = $opens->copy()->setTimezone($timezone);
            $closes = $closes->copy()->setTimezone($timezone);

            // Clip to the branch operating window.
            $open = $opens->greaterThan($branchOpens) ? $opens : $branchOpens->copy();
            $close = $closes->lessThan($branchCloses) ? $closes : $branchCloses->copy();

            if ($close->lessThanOrEqualTo($open)) {
                continue;
            }

            $intervals[] = [$open, $close, max(1, (int) $step)];
        }

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $intervals;
    }

    /**
     * The subtractive (leave / holiday / cancelled) intervals applicable to the
     * branch on the day.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function blockedIntervals(Practitioner $practitioner, TenantBranch $branch, CarbonInterface $day): array
    {
        $timezone = $branch->timezone ?: (string) config('app.timezone');

        $intervals = [];

        foreach ($this->exceptionsForDay($practitioner, $branch, $day) as $exception) {
            if (! $exception->isSubtractive()) {
                continue;
            }

            [$start, $end] = $exception->resolvedRange($timezone);

            $intervals[] = [$start->copy()->setTimezone($timezone), $end->copy()->setTimezone($timezone)];
        }

        return $intervals;
    }

    /**
     * Active exceptions of the practitioner that overlap the day and apply to
     * the branch (a null branch applies everywhere).
     *
     * @return Collection<int, PractitionerScheduleException>
     */
    private function exceptionsForDay(Practitioner $practitioner, TenantBranch $branch, CarbonInterface $day): Collection
    {
        $timezone = $branch->timezone ?: (string) config('app.timezone');
        $dayStart = Carbon::parse($day)->setTimezone($timezone)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();

        return PractitionerScheduleException::query()
            ->where('practitioner_id', $practitioner->getKey())
            ->where('status', PractitionerScheduleException::STATUS_ACTIVE)
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get()
            ->filter(fn (PractitionerScheduleException $exception): bool => $exception->appliesToBranch((string) $branch->getKey()))
            ->values();
    }

    /**
     * @param  list<array{0: Carbon, 1: Carbon}>  $blocked
     */
    private function isBlocked(array $blocked, CarbonInterface $start, CarbonInterface $end): bool
    {
        foreach ($blocked as [$blockStart, $blockEnd]) {
            if ($start->lessThan($blockEnd) && $end->greaterThan($blockStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function occupyingAppointments(TenantBranch $branch, CarbonInterface $day): Collection
    {
        $timezone = $branch->timezone ?: (string) config('app.timezone');
        $dayStart = Carbon::parse($day)->setTimezone($timezone)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();

        return Appointment::query()
            ->where('branch_id', $branch->getKey())
            ->whereIn('status', Appointment::OCCUPYING_STATUSES)
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get(['starts_at', 'ends_at', 'staff_user_id']);
    }
}
