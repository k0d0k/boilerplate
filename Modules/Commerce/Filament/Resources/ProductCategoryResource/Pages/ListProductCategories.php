<?php

namespace Modules\Commerce\Filament\Resources\ProductCategoryResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Commerce\Filament\Resources\ProductCategoryResource;

/**
 * WS3 — list the verified tenant's product categories.
 */
class ListProductCategories extends ListRecords
{
    protected static string $resource = ProductCategoryResource::class;

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
