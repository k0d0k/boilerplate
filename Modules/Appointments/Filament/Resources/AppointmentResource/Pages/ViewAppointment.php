<?php

namespace Modules\Appointments\Filament\Resources\AppointmentResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Appointments\Models\Appointment;

/**
 * Phase 3 — read-only appointment view, including the status history relation
 * manager.
 */
class ViewAppointment extends ViewRecord
{
    protected static string $resource = AppointmentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('lba.appointments.edit'))
                ->url(fn (Appointment $record): string => static::getResource()::getUrl('edit', ['record' => $record]))
                ->visible(fn (Appointment $record): bool => static::getResource()::canEdit($record)),
        ];
    }
}
