<?php

namespace Modules\Appointments\Filament\Resources\AppointmentResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Appointments\Filament\Resources\AppointmentResource;

/**
 * Phase 3 — list the verified tenant's appointments (branch-scoped).
 */
class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
