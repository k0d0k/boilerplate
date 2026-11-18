<?php

namespace Modules\Appointments\Filament\Resources\AppointmentResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Appointments\Services\AppointmentScheduler;

/**
 * Phase 3 — reschedule an appointment of the verified tenant.
 *
 * The status is not editable here: the lifecycle only moves through validated
 * transitions (see the resource's status actions). Updates go through
 * {@see AppointmentScheduler} so the new slot is re-validated for overlaps.
 */
class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();

        try {
            return app(AppointmentScheduler::class)->reschedule($record, $data, $actor instanceof User ? $actor : null);
        } catch (AppointmentConflictException $exception) {
            throw ValidationException::withMessages([
                'data.starts_at' => $exception->getMessage(),
            ]);
        }
    }
}
