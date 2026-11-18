<?php

namespace Modules\Practitioners\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Practitioners\Services\PractitionerRegistry;

/**
 * A professional credential / licence of a practitioner (WS7.A).
 *
 * Extends {@see TenantModel}, so it lives only in the verified tenant's
 * isolated database. Each credential carries its OWN verification decision —
 * actor, timestamp, jurisdiction, type and optional expiry — and starts at
 * `pending`: nothing is auto-verified.
 *
 * `reference_number` (the raw licence identifier), `status`, `verified_at`,
 * `verified_by`, `evidence_*` and `revoked_at` are NOT mass-assignable; only
 * {@see PractitionerRegistry} writes them.
 * The raw reference is masked in every listing ({@see self::maskedReference()})
 * and never written to logs.
 */
#[Fillable([
    'credential_type',
    'jurisdiction',
    'reference_number',
    'issued_at',
    'expires_at',
])]
class PractitionerCredential extends TenantModel
{
    use HasUlids;

    public const TYPE_LICENSE = 'license';

    public const TYPE_REGISTRATION = 'registration';

    public const TYPE_CERTIFICATION = 'certification';

    public const TYPE_DEGREE = 'degree';

    public const TYPE_OTHER = 'other';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_LICENSE,
        self::TYPE_REGISTRATION,
        self::TYPE_CERTIFICATION,
        self::TYPE_DEGREE,
        self::TYPE_OTHER,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_REVOKED,
    ];

    protected $table = 'practitioner_credentials';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'practitioner_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    /**
     * A display-safe reference: only the last four characters are shown, so a
     * list, export or log never exposes the full licence identifier.
     */
    public function maskedReference(): string
    {
        $reference = trim((string) $this->reference_number);

        if ($reference === '') {
            return '—';
        }

        if (mb_strlen($reference) <= 4) {
            return str_repeat('•', mb_strlen($reference));
        }

        return str_repeat('•', mb_strlen($reference) - 4).mb_substr($reference, -4);
    }

    /**
     * @param  Builder<PractitionerCredential>  $query
     * @return Builder<PractitionerCredential>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }
}
