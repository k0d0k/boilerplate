<?php

namespace App\Platform\LbaAssessment\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7A — one indicator definition of a catalog version (CENTRAL database).
 *
 * `source_label` is the preserved legacy label; `observation_label` is the
 * reviewed terminology (NULL until approved). A definition carries no patient
 * data — it is reference metadata only.
 */
#[Fillable([
    'catalog_version_id',
    'key',
    'source_label',
    'source_column',
    'position',
    'observation_label',
    'validation_status',
    'is_active',
    'metadata',
])]
class LbaIndicatorDefinition extends CentralModel
{
    use HasUlids;

    /** The legacy label is preserved but not yet reviewed for clinical use. */
    public const VALIDATION_UNVALIDATED = 'unvalidated';

    /** Kept only as an internal reference / migration aid. */
    public const VALIDATION_REFERENCE_ONLY = 'reference_only';

    /** Reviewed and approved for an appropriate use. */
    public const VALIDATION_APPROVED = 'approved';

    protected $table = 'lba_indicator_definitions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<LbaIndicatorCatalogVersion, $this>
     */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(LbaIndicatorCatalogVersion::class, 'catalog_version_id');
    }

    public function isApproved(): bool
    {
        return $this->validation_status === self::VALIDATION_APPROVED;
    }

    /**
     * The recorded approval provenance of a reviewed indicator, or nulls when no
     * authorized review has been recorded. An approval is only TRUSTWORTHY when
     * it names the approving identity AND the time it was recorded — a label
     * alone is never sufficient.
     *
     * @return array{by: ?string, at: ?string}
     */
    public function approvalProvenance(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        $by = $metadata['approved_by'] ?? null;
        $at = $metadata['approved_at'] ?? null;

        return [
            'by' => is_string($by) && $by !== '' ? $by : null,
            'at' => is_string($at) && $at !== '' ? $at : null,
        ];
    }

    /**
     * True only when this definition may back a patient-facing clinical finding:
     * an EXPLICIT approved status, a reviewed observation label AND a recorded,
     * trustworthy approval provenance. A non-empty observation label on its own
     * is never enough.
     */
    public function hasApprovedTerminology(): bool
    {
        $provenance = $this->approvalProvenance();

        return $this->isApproved()
            && $provenance['by'] !== null
            && $provenance['at'] !== null
            && $this->observation_label !== null
            && $this->observation_label !== '';
    }

    /**
     * The label an operator should read: the reviewed observation label when one
     * exists, otherwise the preserved legacy source label (clearly reference-
     * only while unvalidated).
     */
    public function displayLabel(): string
    {
        return $this->observation_label ?: $this->source_label;
    }
}
