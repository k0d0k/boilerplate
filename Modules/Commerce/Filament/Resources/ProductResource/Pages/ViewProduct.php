<?php

namespace Modules\Commerce\Filament\Resources\ProductResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Modules\Commerce\Filament\Resources\ProductResource;

/**
 * WS3 — view a product and its per-currency prices.
 */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @return array<EditAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
