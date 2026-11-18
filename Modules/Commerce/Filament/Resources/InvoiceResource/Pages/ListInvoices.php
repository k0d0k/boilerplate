<?php

namespace Modules\Commerce\Filament\Resources\InvoiceResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Commerce\Filament\Resources\InvoiceResource;

/**
 * WS3 — list invoices for the verified tenant.
 */
class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
