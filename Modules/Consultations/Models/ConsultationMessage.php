<?php

namespace Modules\Consultations\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a consultation's SINA AI chat transcript (Workstream 2).
 *
 * The transcript lives in the tenant's isolated clinical database. Assistant
 * replies record the provider/model and integer token counters so usage is
 * auditable; credit accounting itself lives in the central SINA wallet.
 */
#[Fillable([
    'consultation_id',
    'role',
    'content',
    'provider',
    'model',
    'prompt_tokens',
    'completion_tokens',
    'credits',
    'created_by',
    'metadata',
])]
class ConsultationMessage extends TenantModel
{
    use HasUlids;

    public const ROLE_PATIENT = 'patient';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const ROLE_PRACTITIONER = 'practitioner';

    /** @var list<string> */
    public const ROLES = [
        self::ROLE_PATIENT,
        self::ROLE_ASSISTANT,
        self::ROLE_SYSTEM,
        self::ROLE_PRACTITIONER,
    ];

    protected $table = 'consultation_messages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'credits' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Consultation, $this>
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }
}
