<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5 — an unreviewed AI detection SUGGESTION for one microscopy image.
 *
 * Stored in its own table, never in `lba_annotations`, so an AI output can never
 * overwrite a human-authored annotation. A detection is a suggestion only: it
 * is not a clinical finding, is never approved, and is never published.
 */
#[Fillable([
    'examination_id',
    'image_id',
    'job_id',
    'provenance',
    'label',
    'geometry',
    'confidence',
    'model_name',
    'model_version',
    'quality_flags',
])]
class Detection extends TenantModel
{
    use HasUlids;

    public const PROVENANCE_AI = 'ai';

    protected $table = 'lba_detections';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'confidence' => 'float',
            'quality_flags' => 'array',
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
     * @return BelongsTo<ExaminationImage, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(ExaminationImage::class, 'image_id');
    }

    /**
     * @return BelongsTo<AnalysisJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class, 'job_id');
    }

    /**
     * Whether the suggestion carries the synthetic marker of the mock adapter.
     */
    public function isSynthetic(): bool
    {
        return in_array('synthetic', (array) $this->quality_flags, true);
    }

    /**
     * The geometry shape of this suggestion, inferred from its normalised
     * geometry: a `points` list is a polygon, a `width`/`height` pair is a
     * rectangle, anything else is a point.
     */
    public function geometryType(): string
    {
        $geometry = (array) $this->geometry;

        if (array_key_exists('points', $geometry)) {
            return Annotation::TYPE_POLYGON;
        }

        if (array_key_exists('width', $geometry) || array_key_exists('height', $geometry)) {
            return Annotation::TYPE_RECTANGLE;
        }

        return Annotation::TYPE_POINT;
    }

    /**
     * A short, operator-facing confidence percentage.
     */
    public function confidencePercent(): string
    {
        return number_format(((float) $this->confidence) * 100, 0).'%';
    }
}
