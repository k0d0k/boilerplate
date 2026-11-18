<?php

namespace Modules\Commerce\Filament\Resources\ProductCategoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Commerce\Filament\Resources\ProductCategoryResource;

/**
 * WS3 — create a category in the verified tenant's isolated database.
 */
class CreateProductCategory extends CreateRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
