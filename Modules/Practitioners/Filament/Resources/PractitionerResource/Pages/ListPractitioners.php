<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Practitioners\Filament\Resources\PractitionerResource;

/**
 * WS7.A — list the verified tenant's practitioner roster.
 */
class ListPractitioners extends ListRecords
{
    protected static string $resource = PractitionerResource::class;

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
