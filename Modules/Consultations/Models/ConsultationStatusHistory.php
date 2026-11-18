<?php

namespace Modules\Consultations\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only consultation status history (Workstream 2).
 *
 * Records every validated status transition so the encounter lifecycle is
 * auditable. `changed_by` is a central user id (no cross-database foreign key).
 */
#[Fillable([
    'consultation_id',
    'from_status',
    'to_status',
    'changed_by',
    'note',
    'changed_at',
])]
class ConsultationStatusHistory extends TenantModel
{
    use HasUlids;

    protected $table = 'consultation_status_histories';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
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
