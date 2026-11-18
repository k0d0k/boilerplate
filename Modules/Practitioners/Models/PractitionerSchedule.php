<?php

namespace Modules\Practitioners\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring weekly practice window of one practitioner at one branch (WS7.B).
 *
 * Extends {@see TenantModel}, so it resolves the verified tenant database at
 * query time and fails closed without one — a schedule can never be read from,
 * or written to, another tenant's database.
 *
 * Times are wall-clock times in the schedule's own timezone (falling back to the
 * branch timezone, then the app timezone). Removal is non-destructive: only the
 * `status` changes, so the history of what was once scheduled is retained.
 */
#[Fillable([
    'branch_id',
    'weekday',
    'starts_at',
    'ends_at',
    'slot_minutes',
    'service_type',
    'timezone',
    'effective_from',
    'effective_until',
])]
class PractitionerSchedule extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'practitioner_schedules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'slot_minutes' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'practitioner_id');
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'branch_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The timezone the wall-clock times are interpreted in.
     */
    public function effectiveTimezone(?string $branchTimezone = null): string
    {
        return $this->timezone ?: $branchTimezone ?: (string) config('app.timezone');
    }

    /**
     * True when the window recurs on the given date: active, within the
     * effective range and on the matching weekday.
     */
    public function coversDate(CarbonInterface $date): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $day = Carbon::parse($date)->setTimezone($this->effectiveTimezone());

        if ((int) $this->weekday !== $day->isoWeekday()) {
            return false;
        }

        if ($this->effective_from !== null && $day->toDateString() < $this->effective_from->toDateString()) {
            return false;
        }

        if ($this->effective_until !== null && $day->toDateString() > $this->effective_until->toDateString()) {
            return false;
        }

        return true;
    }

    /**
     * The concrete open/close instants of the window on the given date, in the
     * schedule timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowFor(CarbonInterface $date, ?string $branchTimezone = null): array
    {
        $day = Carbon::parse($date)->setTimezone($this->effectiveTimezone($branchTimezone));

        $opens = $day->copy()->setTimeFromTimeString((string) $this->starts_at);
        $closes = $day->copy()->setTimeFromTimeString((string) $this->ends_at);

        return [$opens, $closes];
    }

    /**
     * The window's slot length, falling back to the branch/default length.
     */
    public function slotMinutes(int $fallback): int
    {
        $minutes = (int) ($this->slot_minutes ?? 0);

        return $minutes > 0 ? $minutes : $fallback;
    }

    /**
     * null service type on the window means "any service".
     */
    public function appliesToService(?string $serviceType): bool
    {
        return $this->service_type === null
            || $serviceType === null
            || $this->service_type === $serviceType;
    }

    /**
     * @param  Builder<PractitionerSchedule>  $query
     * @return Builder<PractitionerSchedule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
