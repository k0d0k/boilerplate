<?php

namespace Modules\Practitioners\Services;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Closure;
use Illuminate\Support\Carbon;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Models\PractitionerAuditLog;
use Modules\Practitioners\Models\PractitionerSchedule;
use Modules\Practitioners\Models\PractitionerScheduleException;

/**
 * WS7.B — the practice-schedule chokepoint.
 *
 * Every write happens inside a VERIFIED tenant transaction and is re-derived on
 * the SERVER from trusted services (never from client input):
 *
 *  - a schedule window or a schedule exception may only be written for a
 *    practitioner who is a VERIFIED, active clinical member of the verified
 *    tenant AND is explicitly AUTHORIZED for the window's branch;
 *  - the acting identity must hold `practitioner.profile.manage`;
 *  - weekday, time range and effective range are validated, and a new window may
 *    not overlap an existing ACTIVE window of the same practitioner / branch /
 *    weekday;
 *  - removal is non-destructive (only `status` changes), so the schedule history
 *    is retained and every change is audited with actor + timestamp.
 */
final class PractitionerScheduleRegistry
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly ManagesTenantContext $tenants,
        private readonly ChecksPermissions $permissions,
        private readonly ResolvesTenantDatabase $databases,
        private readonly PractitionerRegistry $registry,
    ) {}

    /**
     * Create a recurring weekly window (starts `active`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addWindow(User $actor, Practitioner $practitioner, array $attributes): PractitionerSchedule
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner, $attributes): PractitionerSchedule {
            $branchId = $this->assertSchedulable($practitioner, (string) ($attributes['branch_id'] ?? ''));

            $weekday = (int) ($attributes['weekday'] ?? 0);
            $this->assertWeekday($weekday);

            [$startsAt, $endsAt] = $this->assertWindowTimes(
                (string) ($attributes['starts_at'] ?? ''),
                (string) ($attributes['ends_at'] ?? ''),
            );

            $this->assertEffectiveRange($attributes['effective_from'] ?? null, $attributes['effective_until'] ?? null);

            $schedule = new PractitionerSchedule;
            $schedule->fill($attributes);
            $schedule->forceFill([
                'practitioner_id' => $practitioner->getKey(),
                'branch_id' => $branchId,
                'weekday' => $weekday,
                'status' => PractitionerSchedule::STATUS_ACTIVE,
                'created_by' => (string) $actor->getKey(),
            ]);

            $this->assertNoOverlap($schedule);

            $schedule->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_SCHEDULE_CREATED, $actor, [
                'schedule_id' => $schedule->getKey(),
                'branch_id' => $branchId,
                'weekday' => $weekday,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            return $schedule;
        });
    }

    /**
     * Update an existing window (branch, weekday, times, effective range).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateWindow(User $actor, PractitionerSchedule $schedule, array $attributes): PractitionerSchedule
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $schedule, $attributes): PractitionerSchedule {
            $practitioner = $this->resolvePractitioner($schedule);

            $schedule->fill($attributes);

            $branchId = $this->assertSchedulable($practitioner, (string) $schedule->branch_id);
            $weekday = (int) $schedule->weekday;
            $this->assertWeekday($weekday);
            $this->assertWindowTimes((string) $schedule->starts_at, (string) $schedule->ends_at);
            $this->assertEffectiveRange($schedule->effective_from, $schedule->effective_until);

            $schedule->forceFill(['branch_id' => $branchId, 'weekday' => $weekday]);

            $this->assertNoOverlap($schedule);

            $schedule->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_SCHEDULE_UPDATED, $actor, [
                'schedule_id' => $schedule->getKey(),
                'branch_id' => $branchId,
                'weekday' => $weekday,
                'starts_at' => (string) $schedule->starts_at,
                'ends_at' => (string) $schedule->ends_at,
            ]);

            return $schedule;
        });
    }

    /**
     * Remove a window non-destructively (only `status` changes).
     */
    public function removeWindow(User $actor, PractitionerSchedule $schedule): PractitionerSchedule
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $schedule): PractitionerSchedule {
            $practitioner = $this->resolvePractitioner($schedule);

            $schedule->forceFill(['status' => PractitionerSchedule::STATUS_INACTIVE])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_SCHEDULE_REMOVED, $actor, [
                'schedule_id' => $schedule->getKey(),
            ]);

            return $schedule;
        });
    }

    /**
     * Record a dated exception (leave / holiday / cancelled session /
     * extra session). A null branch applies to every branch.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordException(User $actor, Practitioner $practitioner, array $attributes): PractitionerScheduleException
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $practitioner, $attributes): PractitionerScheduleException {
            $type = (string) ($attributes['type'] ?? '');

            if (! in_array($type, PractitionerScheduleException::TYPES, true)) {
                throw PractitionerException::invalidExceptionType();
            }

            $branchId = $attributes['branch_id'] ?? null;

            if ($branchId !== null && $branchId !== '') {
                $this->assertBranchAuthorized($practitioner, (string) $branchId);
            } else {
                $branchId = null;
            }

            $startsAt = $this->parseInstant((string) ($attributes['starts_at'] ?? ''));
            $endsAt = $this->parseInstant((string) ($attributes['ends_at'] ?? ''));

            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw PractitionerException::invalidTimeRange();
            }

            $exception = new PractitionerScheduleException;
            $exception->fill($attributes);
            $exception->forceFill([
                'practitioner_id' => $practitioner->getKey(),
                'branch_id' => $branchId,
                'type' => $type,
                'status' => PractitionerScheduleException::STATUS_ACTIVE,
                'created_by' => (string) $actor->getKey(),
            ]);

            $exception->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_EXCEPTION_RECORDED, $actor, [
                'exception_id' => $exception->getKey(),
                'type' => $type,
                'branch_id' => $branchId,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ]);

            return $exception;
        });
    }

    /**
     * Cancel an exception non-destructively (only `status` changes).
     */
    public function cancelException(User $actor, PractitionerScheduleException $exception): PractitionerScheduleException
    {
        $tenantId = $this->assertCanManage($actor);

        return $this->withTenant($tenantId, function () use ($actor, $exception): PractitionerScheduleException {
            $practitioner = $this->resolvePractitionerFromException($exception);

            $exception->forceFill(['status' => PractitionerScheduleException::STATUS_INACTIVE])->save();

            $this->audit($practitioner, PractitionerAuditLog::ACTION_EXCEPTION_CANCELLED, $actor, [
                'exception_id' => $exception->getKey(),
            ]);

            return $exception;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Server-side authorization & validation
    |--------------------------------------------------------------------------
    */

    private function assertCanManage(User $actor): string
    {
        if (! $actor->is_active) {
            throw PractitionerException::scheduleNotManageable();
        }

        $tenantId = $this->tenants->currentTenantId($actor);

        if ($tenantId === null || ! $this->permissions->allows(
            $actor,
            PermissionKey::PRACTITIONER_PROFILE_MANAGE,
            AccessContext::forTenant($tenantId, null),
        )) {
            throw PractitionerException::scheduleNotManageable();
        }

        return $tenantId;
    }

    /**
     * The practitioner must be bookable (verified, active, still a clinical
     * member) and authorized for the branch the window belongs to.
     */
    private function assertSchedulable(Practitioner $practitioner, string $branchId): string
    {
        if (! $this->registry->isEligibleForBooking($practitioner)) {
            throw PractitionerException::practitionerNotEligible();
        }

        if ($branchId === '' || TenantBranch::query()->whereKey($branchId)->doesntExist()) {
            throw PractitionerException::branchNotAuthorized();
        }

        $this->assertBranchAuthorized($practitioner, $branchId);

        return $branchId;
    }

    private function assertBranchAuthorized(Practitioner $practitioner, string $branchId): void
    {
        if (! $this->registry->isAuthorizedForBranch($practitioner, $branchId)) {
            throw PractitionerException::branchNotAuthorized();
        }
    }

    private function assertWeekday(int $weekday): void
    {
        if ($weekday < 1 || $weekday > 7) {
            throw PractitionerException::invalidWeekday();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function assertWindowTimes(string $startsAt, string $endsAt): array
    {
        $start = $this->parseTime($startsAt);
        $end = $this->parseTime($endsAt);

        if ($end === null || $start === null || $end <= $start) {
            throw PractitionerException::invalidTimeRange();
        }

        return [$start, $end];
    }

    private function assertEffectiveRange(mixed $from, mixed $until): void
    {
        if ($from === null || $from === '' || $until === null || $until === '') {
            return;
        }

        $fromDate = Carbon::parse((string) $from)->toDateString();
        $untilDate = Carbon::parse((string) $until)->toDateString();

        if ($fromDate > $untilDate) {
            throw PractitionerException::invalidEffectiveRange();
        }
    }

    /**
     * A new/updated window may not overlap an existing ACTIVE window of the same
     * practitioner / branch / weekday whose effective range intersects.
     */
    private function assertNoOverlap(PractitionerSchedule $schedule): void
    {
        $start = $this->parseTime((string) $schedule->starts_at);
        $end = $this->parseTime((string) $schedule->ends_at);

        if ($start === null || $end === null) {
            throw PractitionerException::invalidTimeRange();
        }

        $candidates = PractitionerSchedule::query()
            ->where('practitioner_id', $schedule->practitioner_id)
            ->where('branch_id', $schedule->branch_id)
            ->where('weekday', $schedule->weekday)
            ->where('status', PractitionerSchedule::STATUS_ACTIVE)
            ->when($schedule->exists, fn ($query) => $query->whereKeyNot($schedule->getKey()))
            ->get();

        foreach ($candidates as $candidate) {
            if (! $this->effectiveRangesIntersect($schedule, $candidate)) {
                continue;
            }

            $otherStart = $this->parseTime((string) $candidate->starts_at);
            $otherEnd = $this->parseTime((string) $candidate->ends_at);

            if ($otherStart === null || $otherEnd === null) {
                continue;
            }

            if ($start < $otherEnd && $end > $otherStart) {
                throw PractitionerException::overlappingWindow();
            }
        }
    }

    private function effectiveRangesIntersect(PractitionerSchedule $a, PractitionerSchedule $b): bool
    {
        $aFrom = $a->effective_from?->toDateString();
        $aUntil = $a->effective_until?->toDateString();
        $bFrom = $b->effective_from?->toDateString();
        $bUntil = $b->effective_until?->toDateString();

        $aStartsNotAfterBEnds = $aUntil === null || $bFrom === null || $bFrom <= $aUntil;
        $bStartsNotAfterAEnds = $bUntil === null || $aFrom === null || $aFrom <= $bUntil;

        // Two ranges intersect unless one ends strictly before the other starts.
        return $aStartsNotAfterBEnds && $bStartsNotAfterAEnds;
    }

    private function resolvePractitioner(PractitionerSchedule $schedule): Practitioner
    {
        $practitioner = Practitioner::query()->whereKey($schedule->practitioner_id)->first();

        if ($practitioner === null) {
            throw PractitionerException::unknownPractitioner();
        }

        return $practitioner;
    }

    private function resolvePractitionerFromException(PractitionerScheduleException $exception): Practitioner
    {
        $practitioner = Practitioner::query()->whereKey($exception->practitioner_id)->first();

        if ($practitioner === null) {
            throw PractitionerException::unknownPractitioner();
        }

        return $practitioner;
    }

    /*
    |--------------------------------------------------------------------------
    | Verified-tenant execution, parsing & audit
    |--------------------------------------------------------------------------
    */

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(string $tenantId, Closure $callback): mixed
    {
        $databases = $this->databases;
        $wasBound = $databases->current() === $tenantId;

        try {
            if (! $wasBound) {
                $databases->configure($tenantId);
            }

            return $this->tenantTransaction($callback);
        } finally {
            if (! $wasBound) {
                $databases->forget();
            }
        }
    }

    private function parseTime(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseInstant(string $value): Carbon
    {
        $value = trim($value);

        if ($value === '') {
            throw PractitionerException::invalidTimeRange();
        }

        return Carbon::parse($value);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(Practitioner $practitioner, string $action, ?User $actor, array $changes = []): void
    {
        PractitionerAuditLog::query()->create([
            'practitioner_id' => $practitioner->getKey(),
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'changes' => $changes === [] ? null : $changes,
            'occurred_at' => now(),
        ]);
    }
}
