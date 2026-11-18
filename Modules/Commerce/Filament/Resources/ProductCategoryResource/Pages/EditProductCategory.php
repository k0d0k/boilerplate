<?php

namespace Modules\Commerce\Filament\Resources\ProductCategoryResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Commerce\Filament\Resources\ProductCategoryResource;

/**
 * WS3 — edit a category.
 */
class EditProductCategory extends EditRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
