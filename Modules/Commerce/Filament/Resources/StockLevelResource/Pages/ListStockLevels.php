<?php

namespace Modules\Commerce\Filament\Resources\StockLevelResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Commerce\Filament\Resources\StockLevelResource;

/**
 * WS3 — list branch stock levels for the verified tenant.
 */
class ListStockLevels extends ListRecords
{
    protected static string $resource = StockLevelResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
