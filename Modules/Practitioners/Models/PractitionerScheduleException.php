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
 * A dated override of a practitioner's weekly practice schedule (WS7.B).
 *
 * Extends {@see TenantModel}, so it resolves the verified tenant database at
 * query time and fails closed without one — an exception can never be read from,
 * or written to, another tenant's database.
 *
 * Two families:
 *  - SUBTRACTIVE types (`leave`, `holiday`, `cancelled_session`) remove
 *    availability inside the range;
 *  - ADDITIVE types (`extra_session`) grant availability inside the range
 *    beyond the recurring weekly windows.
 *
 * A null `branch_id` means the exception applies to every branch the
 * practitioner practises at (e.g. annual leave). Cancelling is non-destructive:
 * only the `status` changes, so the history survives.
 */
#[Fillable([
    'branch_id',
    'type',
    'starts_at',
    'ends_at',
    'all_day',
    'reason',
])]
class PractitionerScheduleException extends TenantModel
{
    use HasUlids;

    public const TYPE_LEAVE = 'leave';

    public const TYPE_HOLIDAY = 'holiday';

    public const TYPE_CANCELLED_SESSION = 'cancelled_session';

    public const TYPE_EXTRA_SESSION = 'extra_session';

    /**
     * Types that remove availability from the weekly schedule.
     *
     * @var list<string>
     */
    public const SUBTRACTIVE_TYPES = [
        self::TYPE_LEAVE,
        self::TYPE_HOLIDAY,
        self::TYPE_CANCELLED_SESSION,
    ];

    /**
     * Types that add availability beyond the weekly schedule.
     *
     * @var list<string>
     */
    public const ADDITIVE_TYPES = [
        self::TYPE_EXTRA_SESSION,
    ];

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_LEAVE,
        self::TYPE_HOLIDAY,
        self::TYPE_CANCELLED_SESSION,
        self::TYPE_EXTRA_SESSION,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'practitioner_schedule_exceptions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
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
     * True when the exception removes availability (leave, holiday, cancelled).
     */
    public function isSubtractive(): bool
    {
        return in_array((string) $this->type, self::SUBTRACTIVE_TYPES, true);
    }

    /**
     * True when the exception adds availability (ad-hoc / event session).
     */
    public function isAdditive(): bool
    {
        return in_array((string) $this->type, self::ADDITIVE_TYPES, true);
    }

    /**
     * True when this exception applies to the given branch: a null branch means
     * it applies everywhere; otherwise the branch must match.
     */
    public function appliesToBranch(?string $branchId): bool
    {
        if ($this->branch_id === null) {
            return true;
        }

        return $branchId !== null && (string) $this->branch_id === (string) $branchId;
    }

    /**
     * True when the exception's range overlaps the given [start, end) interval.
     */
    public function overlaps(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $this->starts_at->lessThan($end) && $this->ends_at->greaterThan($start);
    }

    /**
     * True when the exception covers the [start, end) instant range.
     */
    public function covers(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $this->starts_at->lessThanOrEqualTo($start) && $this->ends_at->greaterThanOrEqualTo($end);
    }

    /**
     * The concrete instant range of the exception, optionally widened to the
     * whole local day(s) when it is flagged `all_day`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolvedRange(?string $timezone = null): array
    {
        if (! $this->all_day) {
            return [$this->starts_at->copy(), $this->ends_at->copy()];
        }

        $tz = $timezone ?: (string) config('app.timezone');

        $start = Carbon::parse($this->starts_at)->setTimezone($tz)->startOfDay();
        $end = Carbon::parse($this->ends_at)->setTimezone($tz)->endOfDay();

        return [$start, $end];
    }

    /**
     * @param  Builder<PractitionerScheduleException>  $query
     * @return Builder<PractitionerScheduleException>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @param  Builder<PractitionerScheduleException>  $query
     * @return Builder<PractitionerScheduleException>
     */
    public function scopeSubtractive(Builder $query): Builder
    {
        return $query->whereIn('type', self::SUBTRACTIVE_TYPES);
    }

    /**
     * @param  Builder<PractitionerScheduleException>  $query
     * @return Builder<PractitionerScheduleException>
     */
    public function scopeAdditive(Builder $query): Builder
    {
        return $query->whereIn('type', self::ADDITIVE_TYPES);
    }
}
