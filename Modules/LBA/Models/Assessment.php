<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7A — one digital LBA observation assessment (TENANT database).
 *
 * Tenant-owned and clinical: an assessment belongs to exactly ONE existing
 * examination of the verified tenant, so it can never be read or written across
 * tenants. Extending {@see TenantModel} means it FAILS CLOSED without a verified
 * tenant context rather than hitting the central database.
 *
 * ONE common data model serves all three workflows: `mode` snapshots the
 * examination mode (manual | ai | hybrid) so the same assessment, items and
 * evidence rules apply everywhere. `status` moves through draft →
 * submitted_for_review → returned_for_changes; `reviewed` is reserved for the
 * Phase 7B human-review workflow. There is deliberately NO approve/publish path
 * here — Phase 7A may submit for review but never release a report.
 *
 * The four categories are INTERNAL observation/review priorities, never
 * validated disease-risk probabilities or diagnostic classifications, and an
 * unassessed item is never assumed green (see {@see AssessmentItem}).
 */
#[Fillable([
    'examination_id',
    'catalog_version',
    'catalog_version_id',
    'mode',
    'status',
    'program_phase',
    'brand_id',
    'brand_snapshot',
    'assessed_by',
    'submitted_at',
    'returned_at',
    'reviewed_at',
    'locked_at',
    'last_saved_at',
    'notes',
    'created_by',
])]
class Assessment extends TenantModel
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED_FOR_REVIEW = 'submitted_for_review';

    public const STATUS_RETURNED_FOR_CHANGES = 'returned_for_changes';

    /** Reserved for the Phase 7B human-review workflow. */
    public const STATUS_REVIEWED = 'reviewed';

    /** Phase 7B — a human reviewer rejected the assessment (terminal). */
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED_FOR_REVIEW,
        self::STATUS_RETURNED_FOR_CHANGES,
        self::STATUS_REVIEWED,
        self::STATUS_REJECTED,
    ];

    /**
     * States in which an assessment may still be edited. A submitted, reviewed
     * or otherwise locked assessment is read-only so a finalized snapshot can
     * never be silently overwritten.
     *
     * @var list<string>
     */
    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_RETURNED_FOR_CHANGES,
    ];

    /**
     * The allowed assessment transitions. There is deliberately NO transition
     * to a published/approved report state — that is Phase 7B.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED_FOR_REVIEW],
        self::STATUS_SUBMITTED_FOR_REVIEW => [self::STATUS_RETURNED_FOR_CHANGES, self::STATUS_REVIEWED, self::STATUS_REJECTED],
        self::STATUS_RETURNED_FOR_CHANGES => [self::STATUS_SUBMITTED_FOR_REVIEW],
        self::STATUS_REVIEWED => [],
        self::STATUS_REJECTED => [],
    ];

    public const PROGRAM_CLEANSING = 'cleansing';

    public const PROGRAM_BALANCING = 'balancing';

    public const PROGRAM_PROTECTING = 'protecting';

    public const PROGRAM_MAINTENANCE = 'maintenance';

    public const PROGRAM_NOT_SPECIFIED = 'not_specified';

    /** @var list<string> */
    public const PROGRAM_PHASES = [
        self::PROGRAM_CLEANSING,
        self::PROGRAM_BALANCING,
        self::PROGRAM_PROTECTING,
        self::PROGRAM_MAINTENANCE,
        self::PROGRAM_NOT_SPECIFIED,
    ];

    protected $table = 'lba_assessments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brand_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'returned_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'locked_at' => 'datetime',
            'last_saved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Examination, $this>
     */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }

    /**
     * @return HasMany<AssessmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AssessmentItem::class, 'assessment_id')->orderBy('position');
    }

    /**
     * @return HasMany<AssessmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AssessmentEvent::class, 'assessment_id')->orderBy('occurred_at');
    }

    /**
     * @return HasMany<AssessmentEvidenceLink, $this>
     */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(AssessmentEvidenceLink::class, 'assessment_id');
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'assessment_id')->orderBy('decided_at');
    }

    /**
     * @return HasMany<ReportVersion, $this>
     */
    public function reportVersions(): HasMany
    {
        return $this->hasMany(ReportVersion::class, 'assessment_id')->orderByDesc('version');
    }

    /**
     * @return HasMany<ReportEvent, $this>
     */
    public function reportEvents(): HasMany
    {
        return $this->hasMany(ReportEvent::class, 'assessment_id')->orderBy('occurred_at');
    }

    public function latestReportVersion(): ?ReportVersion
    {
        return $this->reportVersions()->first();
    }

    /**
     * True once a human reviewer approved the assessment and at least one
     * immutable report version exists.
     */
    public function hasApprovedReport(): bool
    {
        return $this->status === self::STATUS_REVIEWED && $this->reportVersions()->exists();
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true) && $this->locked_at === null;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * @param  Builder<Assessment>  $query
     * @return Builder<Assessment>
     */
    public function scopeEditable(Builder $query): Builder
    {
        return $query->whereIn('status', self::EDITABLE_STATUSES)->whereNull('locked_at');
    }

    /**
     * The Filament badge colour for an assessment status.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_SUBMITTED_FOR_REVIEW => 'warning',
            self::STATUS_RETURNED_FOR_CHANGES => 'danger',
            self::STATUS_REVIEWED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }
}
