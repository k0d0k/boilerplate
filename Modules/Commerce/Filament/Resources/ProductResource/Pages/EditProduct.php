<?php

namespace Modules\Commerce\Filament\Resources\ProductResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Commerce\Filament\Resources\ProductResource;

/**
 * WS3 — edit a product. Deleting is never offered; products are archived.
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @return array<DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
