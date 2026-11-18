<?php

namespace Modules\Commerce\Filament\Resources\OrderResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Commerce\Filament\Resources\OrderResource;

/**
 * WS3 — list orders for the verified tenant.
 */
class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
