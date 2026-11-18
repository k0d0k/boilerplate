<?php

namespace Modules\Commerce\Filament\Resources\ProductResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Commerce\Filament\Resources\ProductResource;

/**
 * WS3 — list the verified tenant's products.
 */
class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

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
