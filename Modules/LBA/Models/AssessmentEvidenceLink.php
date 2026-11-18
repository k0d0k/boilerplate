<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7A — evidence attached to an assessed indicator (TENANT database).
 *
 * Evidence refers ONLY to records of the SAME examination and therefore the
 * same tenant: an original image, a human annotation or an (unreviewed) AI
 * detection. The `provenance` snapshot keeps human and AI evidence visually and
 * semantically distinct — an AI detection is NEVER silently promoted to a
 * verified finding or a final colour selection.
 */
#[Fillable([
    'assessment_id',
    'indicator_key',
    'evidence_type',
    'evidence_id',
    'provenance',
    'note',
    'linked_by',
])]
class AssessmentEvidenceLink extends TenantModel
{
    use HasUlids;

    public const TYPE_IMAGE = 'image';

    public const TYPE_ANNOTATION = 'annotation';

    public const TYPE_DETECTION = 'detection';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_ANNOTATION,
        self::TYPE_DETECTION,
    ];

    public const PROVENANCE_HUMAN = 'human';

    public const PROVENANCE_AI = 'ai';

    protected $table = 'lba_assessment_evidence_links';

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }
}
