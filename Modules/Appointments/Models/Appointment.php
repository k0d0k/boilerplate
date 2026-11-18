<?php

namespace Modules\Appointments\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Patients\Models\Patient;

/**
 * A clinic appointment of one tenant (Phase 3).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at
 * query time and fails closed without a verified tenant context. `patient_id`
 * and `branch_id` are tenant-local foreign keys; `staff_user_id` and
 * `created_by` are central user ids stored as plain strings (no cross-database
 * foreign key).
 *
 * The status lifecycle is explicit and one-directional — see {@see self::TRANSITIONS}.
 */
#[Fillable([
    'patient_id',
    'branch_id',
    'staff_user_id',
    'starts_at',
    'ends_at',
    'service_type',
    'status',
    'notes',
    'created_by',
])]
class Appointment extends TenantModel
{
    use HasUlids;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * Statuses that still occupy a practitioner's time slot; a cancelled or
     * no-show appointment no longer blocks a booking.
     *
     * @var list<string>
     */
    public const OCCUPYING_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
        self::STATUS_COMPLETED,
    ];

    /**
     * Terminal statuses may not transition any further.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * The allowed status transitions.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_SCHEDULED => [
            self::STATUS_CONFIRMED,
            self::STATUS_CHECKED_IN,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ],
        self::STATUS_CONFIRMED => [
            self::STATUS_CHECKED_IN,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ],
        self::STATUS_CHECKED_IN => [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    public const SERVICE_CONSULTATION = 'consultation';

    public const SERVICE_LBA_EXAM = 'lba_exam';

    public const SERVICE_FOLLOW_UP = 'follow_up';

    public const SERVICE_OTHER = 'other';

    /** @var list<string> */
    public const SERVICES = [
        self::SERVICE_CONSULTATION,
        self::SERVICE_LBA_EXAM,
        self::SERVICE_FOLLOW_UP,
        self::SERVICE_OTHER,
    ];

    protected $table = 'appointments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'branch_id');
    }

    /**
     * @return HasMany<AppointmentStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class, 'appointment_id')->orderBy('changed_at');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * The Filament badge colour for a status.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_SCHEDULED => 'gray',
            self::STATUS_CONFIRMED => 'info',
            self::STATUS_CHECKED_IN => 'warning',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_CANCELLED, self::STATUS_NO_SHOW => 'danger',
            default => 'gray',
        };
    }
}
