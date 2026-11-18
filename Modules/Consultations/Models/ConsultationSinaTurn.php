<?php

namespace Modules\Consultations\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workstream 9 (WS9.C) — one SINA chat turn's idempotency record.
 *
 * Keyed by (consultation, turn_reference), so a client that retries a dropped
 * request with the SAME optional reference replays the stored reply instead of
 * running the provider again. Lives in the tenant's isolated clinical database
 * alongside the transcript it points at; it stores no message body of its own.
 */
#[Fillable([
    'consultation_id',
    'turn_reference',
    'content_hash',
    'status',
    'patient_message_id',
    'assistant_message_id',
    'attempts',
    'provider_calls',
    'error_code',
])]
class ConsultationSinaTurn extends TenantModel
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    protected $table = 'consultation_sina_turns';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'provider_calls' => 'integer',
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
