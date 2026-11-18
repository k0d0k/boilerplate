<?php

namespace Modules\Commerce\Filament\Resources\PaymentTransactionResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Commerce\Filament\Resources\PaymentTransactionResource;

/**
 * WS3 — list payment transactions for the verified tenant.
 */
class ListTransactions extends ListRecords
{
    protected static string $resource = PaymentTransactionResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
