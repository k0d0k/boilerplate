<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only LBA examination event (Phase 4).
 *
 * Written only by the workflow service on an important state change, carrying
 * the acting identity and a timestamp. Rows are never updated or deleted, so
 * the examination lifecycle stays auditable.
 */
#[Fillable([
    'examination_id',
    'from_status',
    'to_status',
    'actor_id',
    'note',
    'occurred_at',
])]
class ExaminationEvent extends TenantModel
{
    use HasUlids;

    protected $table = 'lba_examination_events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Examination, $this>
     */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }
}
