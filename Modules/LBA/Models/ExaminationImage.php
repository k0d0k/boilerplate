<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One original microscopy image of an LBA examination (Phase 4).
 *
 * The row is an IMMUTABLE reference to the original object held on a private
 * disk. It deliberately exposes NO public URL: `disk` and `object_key` are
 * internal, and the image is only ever read back through the authenticated,
 * tenant-scoped streaming route ({@see self::streamUrl()}). The storage path
 * and original filename are never rendered to the client.
 */
#[Fillable([
    'examination_id',
    'disk',
    'object_key',
    'original_name',
    'checksum',
    'mime_type',
    'width',
    'height',
    'size_bytes',
    'uploaded_by',
    'source',
    'captured_at',
    'instrument_label',
])]
class ExaminationImage extends TenantModel
{
    use HasUlids;

    /** A still imported through the authenticated manual upload form. */
    public const SOURCE_MANUAL_IMPORT = 'manual_import';

    /** A still captured live in the browser (WS6.A). */
    public const SOURCE_BROWSER_CAPTURE = 'browser_capture';

    /** A still delivered by a scoped manufacturer-software/agent credential (WS6.B). */
    public const SOURCE_AGENT_IMPORT = 'agent_import';

    /** Every origin a stored original may carry. */
    public const SOURCES = [
        self::SOURCE_MANUAL_IMPORT,
        self::SOURCE_BROWSER_CAPTURE,
        self::SOURCE_AGENT_IMPORT,
    ];

    protected $table = 'lba_images';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * The moment the still was captured, falling back to the intake time when
     * the origin did not report an explicit capture time. This is the value
     * surfaced in the workspace — it is never a patient or clinical fact.
     */
    public function captureMoment(): ?Carbon
    {
        return $this->captured_at ?? $this->created_at;
    }

    /**
     * The instrument / device label that produced this original, if known.
     * Falls back to the examination's own instrument when the origin is
     * unknown (e.g. a plain manual import).
     */
    public function instrumentName(): ?string
    {
        $label = $this->instrument_label;

        if ($label !== null && $label !== '') {
            return $label;
        }

        return $this->examination?->instrument;
    }

    /**
     * @return BelongsTo<Examination, $this>
     */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }

    /**
     * @return HasMany<Annotation, $this>
     */
    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class, 'image_id');
    }

    /**
     * The authenticated, tenant-scoped streaming URL for this image.
     *
     * This is the ONLY way an image leaves the platform — there is no public
     * direct download URL and no signed URL.
     */
    public function streamUrl(): string
    {
        return route('lba.images.stream', ['image' => $this->getKey()]);
    }

    public function hasDimensions(): bool
    {
        return $this->width !== null && $this->height !== null && $this->width > 0 && $this->height > 0;
    }
}
