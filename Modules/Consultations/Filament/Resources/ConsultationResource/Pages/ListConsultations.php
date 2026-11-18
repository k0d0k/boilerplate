<?php

namespace Modules\Consultations\Filament\Resources\ConsultationResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Consultations\Filament\Resources\ConsultationResource;

/**
 * Workstream 2 — list the verified tenant's consultations (branch-scoped).
 */
class ListConsultations extends ListRecords
{
    protected static string $resource = ConsultationResource::class;

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
