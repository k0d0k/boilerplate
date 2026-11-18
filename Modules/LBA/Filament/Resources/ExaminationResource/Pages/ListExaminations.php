<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\LBA\Filament\Resources\ExaminationResource;

/**
 * Phase 4 — list the verified tenant's LBA examinations (branch-scoped).
 */
class ListExaminations extends ListRecords
{
    protected static string $resource = ExaminationResource::class;

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
