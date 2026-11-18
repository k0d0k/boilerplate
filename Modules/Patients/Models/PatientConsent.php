<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded patient consent (Phase 3).
 *
 * A row only exists once a consent was explicitly captured: it names the
 * consent type, the document version, the recording actor (a central user id,
 * no cross-database foreign key) and the timestamp. The absence of a row means
 * "no consent recorded" — the platform never implies medical consent that was
 * not explicitly recorded.
 */
#[Fillable([
    'patient_id',
    'consent_type',
    'version',
    'status',
    'recorded_by',
    'recorded_at',
    'withdrawn_at',
])]
class PatientConsent extends TenantModel
{
    use HasUlids;

    public const STATUS_GRANTED = 'granted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const TYPE_TREATMENT = 'treatment';

    public const TYPE_PRIVACY = 'privacy';

    public const TYPE_DATA_PROCESSING = 'data_processing';

    public const TYPE_MARKETING = 'marketing';

    /**
     * Phase 4 — the specific consent that authorises clinical examination image
     * processing. It is deliberately distinct from generic privacy/marketing
     * consent: LBA image processing requires THIS consent to be explicitly
     * recorded, never inferred from another grant.
     */
    public const TYPE_EXAMINATION_IMAGE_PROCESSING = 'examination_image_processing';

    /**
     * Workstream 2 — the specific consent that authorises the SINA AI
     * consultation chat. It is deliberately distinct from generic data
     * processing consent: the AI chat requires THIS consent to be explicitly
     * recorded, never inferred from another grant.
     */
    public const TYPE_AI_ASSISTANCE = 'ai_assistance';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_TREATMENT,
        self::TYPE_PRIVACY,
        self::TYPE_DATA_PROCESSING,
        self::TYPE_MARKETING,
        self::TYPE_EXAMINATION_IMAGE_PROCESSING,
        self::TYPE_AI_ASSISTANCE,
    ];

    protected $table = 'patient_consents';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function isGranted(): bool
    {
        return $this->status === self::STATUS_GRANTED && $this->withdrawn_at === null;
    }

    public function withdraw(): void
    {
        $this->forceFill([
            'status' => self::STATUS_WITHDRAWN,
            'withdrawn_at' => now(),
        ])->save();
    }
}
