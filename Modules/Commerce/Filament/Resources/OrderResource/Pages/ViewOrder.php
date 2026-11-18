<?php

namespace Modules\Commerce\Filament\Resources\OrderResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Commerce\Filament\Resources\OrderResource;

/**
 * WS3 — view a single order: its lines, invoice(s) and payment history.
 */
class ViewOrder extends ViewRecord
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
