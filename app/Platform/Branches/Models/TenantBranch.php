<?php

namespace App\Platform\Branches\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A branch of one tenant (Phase 2B).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at query
 * time and fails closed without a verified tenant context — a branch can never
 * be read from, or written to, the central database or another tenant's
 * database.
 *
 * A branch deliberately carries no tenant or brand identity: the tenant is the
 * database it lives in, and a brand is a commercial label, not a location
 * (ARCH-05). Deactivation is non-destructive — the row is retained, only the
 * status changes.
 */
#[Fillable([
    'name',
    'code',
    'location_type',
    'address',
    'city',
    'region',
    'country',
    'timezone',
    'status',
    'phone',
    'email',
    'operates_from',
    'operates_until',
    'opens_at',
    'closes_at',
    'slot_minutes',
    'capacity',
])]
class TenantBranch extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED,
    ];

    public const LOCATION_PERMANENT = 'permanent';

    public const LOCATION_TEMPORARY = 'temporary';

    public const LOCATION_EVENT = 'event';

    /** @var list<string> */
    public const LOCATION_TYPES = [
        self::LOCATION_PERMANENT,
        self::LOCATION_TEMPORARY,
        self::LOCATION_EVENT,
    ];

    /** The platform-wide default slot length, in minutes. */
    public const DEFAULT_SLOT_MINUTES = 30;

    /** The daily bookable window used when a branch declares none. */
    public const DEFAULT_OPENS_AT = '08:00';

    public const DEFAULT_CLOSES_AT = '17:00';

    protected $table = 'branches';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
            'operates_from' => 'datetime',
            'operates_until' => 'datetime',
            'slot_minutes' => 'integer',
            'capacity' => 'integer',
        ];
    }

    /**
     * @return HasMany<BranchStaffAssignment, $this>
     */
    public function staffAssignments(): HasMany
    {
        return $this->hasMany(BranchStaffAssignment::class, 'branch_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPermanent(): bool
    {
        return $this->location_type === self::LOCATION_PERMANENT
            || $this->location_type === null;
    }

    public function isTemporary(): bool
    {
        return $this->location_type === self::LOCATION_TEMPORARY;
    }

    public function isEvent(): bool
    {
        return $this->location_type === self::LOCATION_EVENT;
    }

    /**
     * A tentative (unconfirmed) site: a temporary / event location that has not
     * been activated yet. It is only ever offered as preregistration — it
     * publishes no bookable slot and no booking can be confirmed against it
     * until an operator activates it (FEATURES_BOOKING_CONSULTATION_COMMERCE).
     */
    public function isTentative(): bool
    {
        return ! $this->isPermanent() && ! $this->isActive();
    }

    /**
     * A temporary / event site is bookable only inside its declared window; a
     * permanent branch is always bookable (while active).
     */
    public function isBookableOn(CarbonInterface $date): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->isPermanent()) {
            return true;
        }

        $moment = Carbon::parse($date);

        if ($this->operates_from !== null && $moment->lessThan($this->operates_from)) {
            return false;
        }

        if ($this->operates_until !== null && $moment->greaterThan($this->operates_until)) {
            return false;
        }

        return true;
    }

    public function effectiveSlotMinutes(): int
    {
        $minutes = (int) ($this->slot_minutes ?? 0);

        return $minutes > 0 ? $minutes : self::DEFAULT_SLOT_MINUTES;
    }

    /**
     * The maximum number of overlapping occupying appointments, or null when
     * the site imposes no branch-level cap (the historical behaviour).
     */
    public function effectiveCapacity(): ?int
    {
        $capacity = $this->capacity;

        return $capacity === null || $capacity < 1 ? null : (int) $capacity;
    }

    /**
     * The daily bookable window (opens/closes) for the given day, in the
     * branch timezone. Falls back to the platform default window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function openingWindow(CarbonInterface $date): array
    {
        $day = Carbon::parse($date)->setTimezone($this->timezone ?: config('app.timezone'));

        $opensAt = $this->opens_at ?: self::DEFAULT_OPENS_AT;
        $closesAt = $this->closes_at ?: self::DEFAULT_CLOSES_AT;

        $opens = $day->copy()->setTimeFromTimeString($opensAt);
        $closes = $day->copy()->setTimeFromTimeString($closesAt);

        return [$opens, $closes];
    }

    /**
     * Non-destructive activation.
     */
    public function activate(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'deactivated_at' => null,
        ])->save();
    }

    /**
     * Non-destructive deactivation: the branch row is retained so historical
     * references stay intact; only its status changes.
     */
    public function deactivate(): void
    {
        $this->forceFill([
            'status' => self::STATUS_INACTIVE,
            'deactivated_at' => now(),
        ])->save();
    }

    /**
     * @param  Builder<TenantBranch>  $query
     * @return Builder<TenantBranch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
