<?php

namespace Modules\Patients\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Services\PatientAccountLinker;

/**
 * A patient of one tenant (Phase 3).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at
 * query time and fails closed without a verified tenant context — a patient can
 * never be read from, or written to, the central database or another tenant's
 * database.
 *
 * A patient carries no tenant identity: the tenant is the database the row
 * lives in. The tenant-local patient number is unique within that database.
 * Archiving is non-destructive — the row is retained, only the status changes,
 * so a patient's history across every branch of the tenant survives.
 *
 * The portal account binding (`user_id`) is DELIBERATELY not mass-assignable:
 * it is never fillable, so untrusted self-service input can never attach an
 * account. An authorized, auditable clinic action must set it explicitly
 * ({@see PatientAccountLinker}).
 */
#[Fillable([
    'patient_number',
    'full_name',
    'date_of_birth',
    'sex',
    'gender',
    'phone',
    'email',
    'address',
    'city',
    'region',
    'country',
    'registered_branch_id',
    'registered_at',
    'status',
])]
class Patient extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];

    public const SEX_MALE = 'male';

    public const SEX_FEMALE = 'female';

    public const SEX_OTHER = 'other';

    public const SEX_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const SEXES = [
        self::SEX_MALE,
        self::SEX_FEMALE,
        self::SEX_OTHER,
        self::SEX_UNKNOWN,
    ];

    protected $table = 'patients';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'registered_at' => 'datetime',
            'archived_at' => 'datetime',
            'portal_invite_expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PatientConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class, 'patient_id');
    }

    /**
     * @return HasMany<PatientAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(PatientAuditLog::class, 'patient_id');
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function registeredBranch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'registered_branch_id');
    }

    /**
     * Appointment history, owned by the Appointments module.
     *
     * Resolved lazily by class name so the Patients module never hard-depends
     * on the Appointments module being installed.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * True once an authorized clinic action has bound a portal account to this
     * patient record.
     */
    public function isLinked(): bool
    {
        return $this->user_id !== null;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Non-destructive archiving: the patient row is retained so history stays
     * intact; only the status changes.
     */
    public function archive(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->save();
    }

    /**
     * Non-destructive reactivation.
     */
    public function restore(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'archived_at' => null,
        ])->save();
    }

    /**
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
