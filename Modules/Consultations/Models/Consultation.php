<?php

namespace Modules\Consultations\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Models\Patient;

/**
 * A clinic consultation of one tenant (Workstream 2).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at
 * query time and fails closed without a verified tenant context. `patient_id`,
 * `branch_id` and `appointment_id` are tenant-local foreign keys; `staff_user_id`
 * (the practitioner) and `created_by` are central user ids stored as plain
 * strings (no cross-database foreign key).
 *
 * The status lifecycle is explicit and one-directional — see {@see self::TRANSITIONS}.
 */
#[Fillable([
    'patient_id',
    'branch_id',
    'staff_user_id',
    'appointment_id',
    'channel',
    'type',
    'status',
    'scheduled_at',
    'started_at',
    'ended_at',
    'reason',
    'summary',
    'created_by',
])]
class Consultation extends TenantModel
{
    use HasUlids;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * Statuses in which a consultation is still live and may accept SINA chat.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
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
            self::STATUS_IN_PROGRESS,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ],
        self::STATUS_IN_PROGRESS => [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    public const CHANNEL_IN_PERSON = 'in_person';

    public const CHANNEL_SINA_CHAT = 'sina_chat';

    public const CHANNEL_TELECONSULT = 'teleconsult';

    /** @var list<string> */
    public const CHANNELS = [
        self::CHANNEL_IN_PERSON,
        self::CHANNEL_SINA_CHAT,
        self::CHANNEL_TELECONSULT,
    ];

    public const TYPE_CONSULTATION = 'consultation';

    public const TYPE_FOLLOW_UP = 'follow_up';

    public const TYPE_LBA_REVIEW = 'lba_review';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_CONSULTATION,
        self::TYPE_FOLLOW_UP,
        self::TYPE_LBA_REVIEW,
    ];

    protected $table = 'consultations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /**
     * @return HasMany<ConsultationStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(ConsultationStatusHistory::class, 'consultation_id')->orderBy('changed_at');
    }

    /**
     * @return HasMany<ConsultationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConsultationMessage::class, 'consultation_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<ConsultationAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(ConsultationAuditLog::class, 'consultation_id')->orderByDesc('occurred_at');
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

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * The Filament badge colour for a status.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_SCHEDULED => 'gray',
            self::STATUS_IN_PROGRESS => 'warning',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_CANCELLED, self::STATUS_NO_SHOW => 'danger',
            default => 'gray',
        };
    }
}
