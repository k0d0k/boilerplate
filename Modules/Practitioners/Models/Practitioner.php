<?php

namespace Modules\Practitioners\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Practitioners\Services\PractitionerRegistry;

/**
 * A practitioner of one tenant (WS7.A).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at
 * query time and fails closed without a verified tenant context — a
 * practitioner can never be read from, or written to, the central database or
 * another tenant's database.
 *
 * `user_id` is the id of a CENTRAL user (the practitioner's identity) stored as
 * a plain string (no cross-database foreign key) and is DELIBERATELY not
 * mass-assignable: it is never fillable, so untrusted input can never attach an
 * identity. Only {@see PractitionerRegistry}
 * writes it — after verifying an active clinical membership.
 *
 * Being listed as a doctor is NOT evidence of qualification: `status` starts at
 * `pending` and may only become `verified` through an explicit, audited
 * verification by authorized clinic personnel (never automatically).
 */
#[Fillable([
    'display_name',
    'title',
    'professional_body',
    'specialities',
    'notes',
])]
class Practitioner extends TenantModel
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_SUSPENDED,
        self::STATUS_INACTIVE,
    ];

    protected $table = 'practitioners';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'specialities' => 'array',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PractitionerCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(PractitionerCredential::class, 'practitioner_id');
    }

    /**
     * @return HasMany<PractitionerAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(PractitionerAuditLog::class, 'practitioner_id');
    }

    /**
     * @return HasMany<PractitionerBranchAuthorization, $this>
     */
    public function branchAuthorizations(): HasMany
    {
        return $this->hasMany(PractitionerBranchAuthorization::class, 'practitioner_id');
    }

    /**
     * @return HasMany<PractitionerSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(PractitionerSchedule::class, 'practitioner_id');
    }

    /**
     * @return HasMany<PractitionerScheduleException, $this>
     */
    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(PractitionerScheduleException::class, 'practitioner_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    /**
     * True once the practitioner's own verification window has lapsed. A null
     * expiry never expires.
     */
    public function isExpired(?CarbonInterface $at = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    /**
     * The professional-status gate: a practitioner may be offered to patients
     * and exercise qualified clinical actions only while verified and not
     * expired. Membership, role, branch assignment and schedule are checked
     * separately by the booking layer.
     */
    public function isVerifiedActive(?CarbonInterface $at = null): bool
    {
        return $this->isVerified() && ! $this->isExpired($at);
    }

    /**
     * @param  Builder<Practitioner>  $query
     * @return Builder<Practitioner>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    /**
     * @param  Builder<Practitioner>  $query
     * @return Builder<Practitioner>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_INACTIVE);
    }
}
