<?php

namespace Modules\Appointments\Filament\Resources\AppointmentResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Appointments\Services\AppointmentScheduler;

/**
 * Phase 3 — book an appointment in the verified tenant's isolated database.
 *
 * Creation goes through {@see AppointmentScheduler} so the booking is
 * transactionally validated (tenant-local patient/branch, eligible staff,
 * double-booking) and the initial status is written to the status history.
 */
class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        try {
            return app(AppointmentScheduler::class)->create($data, $actor instanceof User ? $actor : null);
        } catch (AppointmentConflictException $exception) {
            throw ValidationException::withMessages([
                'data.starts_at' => $exception->getMessage(),
            ]);
        }
    }
}
