<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 7B — one IMMUTABLE, versioned PDF report (TENANT database).
 *
 * A report version is written exactly once. It snapshots the brand, branch and
 * approved reviewer identities at generation time, so a later rename, a moved
 * branch or a removed user can never rewrite a delivered document. The PDF is
 * stored on the PRIVATE `lba-reports` disk and identified by a SHA-256 checksum
 * of its bytes; there is no public URL and no signed link.
 *
 * A revision (return for changes → correct → re-approve) creates a NEW version;
 * existing versions are never overwritten. Integrity is verifiable by
 * re-hashing the stored object and comparing it against `checksum`.
 */
#[Fillable([
    'assessment_id',
    'version',
    'status',
    'locale',
    'brand_id',
    'brand_snapshot',
    'branch_id',
    'branch_snapshot',
    'reviewer_id',
    'reviewer_snapshot',
    'approved_at',
    'released_at',
    'released_by',
    'checksum',
    'disk',
    'object_key',
    'mime_type',
    'size_bytes',
    'generated_by',
    'generated_at',
])]
class ReportVersion extends TenantModel
{
    use HasUlids;

    /** A generated, immutable PDF version. */
    public const STATUS_GENERATED = 'generated';

    public const MIME_PDF = 'application/pdf';

    protected $table = 'lba_report_versions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size_bytes' => 'integer',
            'brand_snapshot' => 'array',
            'branch_snapshot' => 'array',
            'reviewer_snapshot' => 'array',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'generated_at' => 'datetime',
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
     * True once an authorized clinic identity has EXPLICITLY released this exact
     * version to the patient portal. Approval alone never releases: a version
     * stays private until then, and every historically approved version remains
     * unreleased by default.
     */
    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    public function filename(): string
    {
        return 'lba-report-'.$this->assessment_id.'-v'.$this->version.'.pdf';
    }

    /**
     * Verify the stored object still matches the recorded checksum. Reads the
     * private object back and re-hashes it; a mismatch means the document was
     * altered after generation and must not be trusted.
     */
    public function verifyChecksum(): bool
    {
        $disk = Storage::disk((string) $this->disk);

        if (! $disk->exists((string) $this->object_key)) {
            return false;
        }

        return hash_equals((string) $this->checksum, hash('sha256', (string) $disk->get((string) $this->object_key)));
    }
}
