<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7A — one assessed indicator of an assessment (TENANT database).
 *
 * `category` is ONE explicit value out of four — green | yellow | red |
 * unassessed — stored as a single column, never three independent booleans.
 * It defaults to `unassessed`: an item the evaluator has not looked at is NEVER
 * assumed green.
 *
 * These categories are INTERNAL observation/review priorities, not validated
 * disease-risk probabilities or diagnostic classifications. The indicator
 * labels and the catalog version are SNAPSHOT onto the item, so a later catalog
 * edit cannot silently rewrite what was assessed historically.
 */
#[Fillable([
    'assessment_id',
    'indicator_key',
    'catalog_version',
    'source_label',
    'observation_label',
    'terminology_status',
    'terminology_approved_by',
    'terminology_approved_at',
    'source_column',
    'position',
    'category',
    'notes',
    'assessor_id',
    'assessed_at',
    'revision',
    'provenance',
])]
class AssessmentItem extends TenantModel
{
    use HasUlids;

    public const CATEGORY_GREEN = 'green';

    public const CATEGORY_YELLOW = 'yellow';

    public const CATEGORY_RED = 'red';

    public const CATEGORY_UNASSESSED = 'unassessed';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_GREEN,
        self::CATEGORY_YELLOW,
        self::CATEGORY_RED,
        self::CATEGORY_UNASSESSED,
    ];

    /** The categories an evaluator may actively record (unassessed is the reset). */
    public const RECORDABLE_CATEGORIES = [
        self::CATEGORY_GREEN,
        self::CATEGORY_YELLOW,
        self::CATEGORY_RED,
    ];

    public const PROVENANCE_HUMAN = 'human';

    public const PROVENANCE_AI = 'ai';

    /**
     * The explicit terminology VALIDATION statuses snapshotted from the central
     * catalog. Only `approved` may ever back a patient-facing clinical finding.
     */
    public const TERMINOLOGY_UNVALIDATED = 'unvalidated';

    public const TERMINOLOGY_REFERENCE_ONLY = 'reference_only';

    public const TERMINOLOGY_APPROVED = 'approved';

    protected $table = 'lba_assessment_items';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'revision' => 'integer',
            'assessed_at' => 'datetime',
            'terminology_approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    /**
     * @return HasMany<AssessmentEvidenceLink, $this>
     */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(AssessmentEvidenceLink::class, 'indicator_key', 'indicator_key');
    }

    public function isUnassessed(): bool
    {
        return $this->category === self::CATEGORY_UNASSESSED;
    }

    /**
     * True only when this item may back a patient-facing clinical finding.
     *
     * Three things are required, and a non-empty `observation_label` alone is
     * NEVER sufficient:
     *  - an EXPLICIT approved terminology status (`terminology_status`), and
     *  - a trustworthy approval provenance (who approved it and when), and
     *  - the reviewed observation label itself.
     *
     * Every legacy label is transcribed as-is with NO reviewed observation
     * label, NO approved status and NO approval provenance, so each one is
     * REFERENCE-ONLY and unvalidated: it must never be presented as a validated
     * clinical finding, and its green/yellow/red selection must never be
     * surfaced as a patient-facing diagnostic risk.
     */
    public function isValidated(): bool
    {
        return $this->terminology_status === self::TERMINOLOGY_APPROVED
            && $this->terminology_approved_by !== null
            && $this->terminology_approved_by !== ''
            && $this->terminology_approved_at !== null
            && $this->observation_label !== null
            && $this->observation_label !== '';
    }

    public function isReferenceOnly(): bool
    {
        return ! $this->isValidated();
    }

    /**
     * The label an operator should read: the reviewed observation label when one
     * exists, otherwise the preserved legacy source label.
     */
    public function displayLabel(): string
    {
        return $this->observation_label ?: $this->source_label;
    }

    /**
     * The Filament badge / selector colour for a category.
     */
    public static function categoryColor(string $category): string
    {
        return match ($category) {
            self::CATEGORY_GREEN => 'success',
            self::CATEGORY_YELLOW => 'warning',
            self::CATEGORY_RED => 'danger',
            default => 'gray',
        };
    }
}
