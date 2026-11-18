<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 5 — one asynchronous AI vision analysis job for a microscopy image.
 *
 * Tenant-owned and isolated: the row only ever exists in the verified tenant
 * database. The external gateway sees ONLY `request_ref` (an opaque ULID) plus
 * the image bytes/checksum — never a patient identity or clinical note.
 *
 * The status machine is explicit and one-directional. A JOB completing never
 * changes an examination's clinical state and never approves anything: its
 * detections are stored as unreviewed suggestions.
 */
#[Fillable([
    'examination_id',
    'image_id',
    'request_ref',
    'status',
    'provider',
    'model_name',
    'model_version',
    'attempts',
    'error_code',
    'idempotency_key',
    'requested_by',
    'started_at',
    'completed_at',
    'billable',
    'pricing_rule_ref',
    'unit_price_credits',
    'credits_state',
])]
class AnalysisJob extends TenantModel
{
    use HasUlids;

    /**
     * Phase 6 — the durable billing state of a job. It lives on the tenant row
     * so an interrupted cross-database operation can be reconciled against the
     * central ledger, which is the source of truth.
     */
    public const CREDITS_NONE = null;

    public const CREDITS_RESERVED = 'reserved';

    public const CREDITS_CAPTURED = 'captured';

    public const CREDITS_RELEASED = 'released';

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Allowed job transitions. `completed` and `cancelled` are terminal; a
     * `failed` job may only be re-queued by an explicit, attempt-limited retry.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_QUEUED, self::STATUS_CANCELLED],
        self::STATUS_QUEUED => [self::STATUS_PROCESSING, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [self::STATUS_QUEUED, self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    /** Error codes a failed job may carry (safe, non-identifying). */
    public const ERROR_GATEWAY_UNAVAILABLE = 'gateway_unavailable';

    public const ERROR_INVALID_RESPONSE = 'invalid_response';

    public const ERROR_CONSENT_REQUIRED = 'consent_required';

    public const ERROR_IMAGE_UNREADABLE = 'image_unreadable';

    protected $table = 'lba_analysis_jobs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'billable' => 'boolean',
            'unit_price_credits' => 'integer',
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
     * @return HasMany<Detection, $this>
     */
    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class, 'job_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Phase 6 — whether this job carries a billable reservation.
     */
    public function isBillable(): bool
    {
        return (bool) $this->billable;
    }

    /**
     * The immutable per-job price snapshot captured at reservation time.
     */
    public function creditUnits(): int
    {
        return (int) ($this->unit_price_credits ?? 0);
    }

    public function creditsReserved(): bool
    {
        return $this->credits_state === self::CREDITS_RESERVED;
    }

    /**
     * The operator-facing AI processing state. `completed` reads as
     * "results ready" — the job's suggestions are available for human review.
     * A job state never implies a clinical finding: nothing is approved here.
     */
    public function processingState(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING, self::STATUS_QUEUED => 'queued',
            self::STATUS_PROCESSING => 'processing',
            self::STATUS_COMPLETED => 'results_ready',
            self::STATUS_FAILED => 'failed',
            default => 'cancelled',
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'gray',
            self::STATUS_QUEUED => 'info',
            self::STATUS_PROCESSING => 'warning',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'gray',
            default => 'gray',
        };
    }
}
