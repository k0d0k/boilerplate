<?php

namespace Modules\Appointments\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only status-transition log for one appointment (Phase 3).
 *
 * `changed_by` is a central user id stored as a plain string (no cross-database
 * foreign key).
 */
#[Fillable([
    'appointment_id',
    'from_status',
    'to_status',
    'changed_by',
    'note',
    'changed_at',
])]
class AppointmentStatusHistory extends TenantModel
{
    use HasUlids;

    protected $table = 'appointment_status_histories';

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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }
}
