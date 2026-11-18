<?php

namespace Modules\Patients\Filament\Resources\PatientResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Patients\Filament\Resources\PatientResource;

/**
 * Phase 3 — list the verified tenant's patients.
 */
class ListPatients extends ListRecords
{
    protected static string $resource = PatientResource::class;

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
