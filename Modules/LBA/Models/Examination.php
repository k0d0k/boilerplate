<?php

namespace Modules\LBA\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;

/**
 * An LBA examination of one tenant (Phase 4).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at
 * query time and fails closed without a verified tenant context — an
 * examination can never be read from, or written to, the central database or
 * another tenant's database. `patient_id`, `branch_id` and `appointment_id` are
 * tenant-local foreign keys; `operator_user_id` and `created_by` are central
 * user ids stored as plain strings (no cross-database foreign key).
 *
 * `mode` is manual | ai | hybrid. Phase 4 only PROCESSES `manual`; the other
 * two may be recorded but are explicitly marked not yet available.
 *
 * The workflow is explicit and one-directional — see {@see self::TRANSITIONS}.
 * Direct approval / publication is deliberately absent (Phase 7).
 */
#[Fillable([
    'patient_id',
    'branch_id',
    'appointment_id',
    'operator_user_id',
    'mode',
    'status',
    'captured_at',
    'instrument',
    'magnification',
    'calibration',
    'notes',
    'consent_id',
    'created_by',
])]
class Examination extends TenantModel
{
    use HasUlids;

    public const MODE_MANUAL = 'manual';

    public const MODE_AI = 'ai';

    public const MODE_HYBRID = 'hybrid';

    /** @var list<string> */
    public const MODES = [
        self::MODE_MANUAL,
        self::MODE_AI,
        self::MODE_HYBRID,
    ];

    /**
     * Modes the platform can process. Manual is unchanged and needs no AI;
     * AI and Hybrid can additionally run the asynchronous vision gateway.
     */
    public const PROCESSABLE_MODES = [self::MODE_MANUAL, self::MODE_AI, self::MODE_HYBRID];

    /** Modes that may run AI vision analysis. */
    public const AI_MODES = [self::MODE_AI, self::MODE_HYBRID];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_MANUAL_ANNOTATION = 'manual_annotation';

    public const STATUS_REVIEW_REQUIRED = 'review_required';

    public const STATUS_RETURNED_FOR_CHANGES = 'returned_for_changes';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_UPLOADED,
        self::STATUS_MANUAL_ANNOTATION,
        self::STATUS_REVIEW_REQUIRED,
        self::STATUS_RETURNED_FOR_CHANGES,
        self::STATUS_CANCELLED,
    ];

    /**
     * States in which an examination's own data may still be edited and images
     * may still be added. Review states are read-only here.
     *
     * @var list<string>
     */
    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_UPLOADED,
        self::STATUS_MANUAL_ANNOTATION,
        self::STATUS_RETURNED_FOR_CHANGES,
    ];

    /**
     * The allowed workflow transitions.
     *
     * There is deliberately NO transition to `approved` or `published`: those
     * states do not exist in Phase 4 (human review and immutable reporting are
     * Phase 7). `cancelled` is the only terminal state.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_UPLOADED, self::STATUS_CANCELLED],
        self::STATUS_UPLOADED => [self::STATUS_MANUAL_ANNOTATION, self::STATUS_CANCELLED],
        self::STATUS_MANUAL_ANNOTATION => [self::STATUS_REVIEW_REQUIRED, self::STATUS_CANCELLED],
        self::STATUS_REVIEW_REQUIRED => [self::STATUS_RETURNED_FOR_CHANGES, self::STATUS_CANCELLED],
        self::STATUS_RETURNED_FOR_CHANGES => [self::STATUS_MANUAL_ANNOTATION, self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    protected $table = 'lba_examinations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'calibration' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'branch_id');
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /**
     * @return BelongsTo<PatientConsent, $this>
     */
    public function consent(): BelongsTo
    {
        return $this->belongsTo(PatientConsent::class, 'consent_id');
    }

    /**
     * @return HasMany<ExaminationImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ExaminationImage::class, 'examination_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<Annotation, $this>
     */
    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class, 'examination_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<ExaminationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ExaminationEvent::class, 'examination_id')->orderBy('occurred_at');
    }

    /**
     * @return HasMany<AnalysisJob, $this>
     */
    public function analysisJobs(): HasMany
    {
        return $this->hasMany(AnalysisJob::class, 'examination_id')->orderByDesc('created_at');
    }

    /**
     * @return HasMany<Detection, $this>
     */
    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class, 'examination_id')->orderByDesc('created_at');
    }

    /**
     * @return HasMany<Assessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'examination_id')->orderByDesc('created_at');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Whether Phase 4 can process this examination's mode.
     */
    public function isManuallyProcessable(): bool
    {
        return $this->mode === self::MODE_MANUAL;
    }

    /**
     * Whether this examination's mode may run AI vision analysis.
     */
    public function isAiCapable(): bool
    {
        return in_array($this->mode, self::AI_MODES, true);
    }

    /**
     * @param  Builder<Examination>  $query
     * @return Builder<Examination>
     */
    public function scopeEditable(Builder $query): Builder
    {
        return $query->whereIn('status', self::EDITABLE_STATUSES);
    }

    /**
     * Whether this examination currently holds an explicit, still-granted
     * examination image-processing consent. Image processing is refused until
     * this is true — generic privacy or marketing consent never counts.
     */
    public function hasGrantedImageProcessingConsent(): bool
    {
        if ($this->consent_id === null) {
            return false;
        }

        return PatientConsent::query()
            ->whereKey($this->consent_id)
            ->where('patient_id', $this->patient_id)
            ->where('consent_type', PatientConsent::TYPE_EXAMINATION_IMAGE_PROCESSING)
            ->where('status', PatientConsent::STATUS_GRANTED)
            ->whereNull('withdrawn_at')
            ->exists();
    }

    /**
     * The Filament badge colour for a status.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_UPLOADED => 'info',
            self::STATUS_MANUAL_ANNOTATION => 'info',
            self::STATUS_REVIEW_REQUIRED => 'warning',
            self::STATUS_RETURNED_FOR_CHANGES => 'danger',
            self::STATUS_CANCELLED => 'gray',
            default => 'gray',
        };
    }

    /**
     * The Filament badge colour for a mode.
     */
    public static function modeColor(string $mode): string
    {
        return match ($mode) {
            self::MODE_MANUAL => 'success',
            self::MODE_AI => 'info',
            self::MODE_HYBRID => 'warning',
            default => 'gray',
        };
    }
}
