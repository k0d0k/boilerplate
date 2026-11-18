<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual (or, later, AI) annotation on an examination image (Phase 4).
 *
 * `job_id` is NULLABLE on purpose: a manual annotation is authored by a human
 * with NO AI job, so the manual workflow works with the AI stack offline.
 * `geometry` stores coordinates NORMALISED against the original image (0..1),
 * so an annotation's stored position never depends on the viewer's zoom, pan or
 * screen size. Labels describe operator OBSERVATIONS, never automatic medical
 * diagnoses.
 */
#[Fillable([
    'examination_id',
    'image_id',
    'job_id',
    'provenance',
    'type',
    'geometry',
    'label',
    'notes',
    'actor_id',
    'revision',
])]
class Annotation extends TenantModel
{
    use HasUlids;

    public const PROVENANCE_HUMAN = 'human';

    public const PROVENANCE_AI = 'ai';

    /** @var list<string> */
    public const PROVENANCES = [self::PROVENANCE_HUMAN, self::PROVENANCE_AI];

    public const TYPE_POINT = 'point';

    public const TYPE_RECTANGLE = 'rectangle';

    public const TYPE_POLYGON = 'polygon';

    /** @var list<string> */
    public const TYPES = [self::TYPE_POINT, self::TYPE_RECTANGLE, self::TYPE_POLYGON];

    protected $table = 'lba_annotations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'revision' => 'integer',
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

    public function isManual(): bool
    {
        return $this->provenance === self::PROVENANCE_HUMAN && $this->job_id === null;
    }
}
