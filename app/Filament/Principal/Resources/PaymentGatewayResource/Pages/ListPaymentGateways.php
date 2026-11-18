<?php

namespace App\Filament\Principal\Resources\PaymentGatewayResource\Pages;

use App\Filament\Principal\Resources\PaymentGatewayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * WS3 — list the central payment gateway configuration.
 */
class ListPaymentGateways extends ListRecords
{
    protected static string $resource = PaymentGatewayResource::class;

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(static fn (): bool => PaymentGatewayResource::canCreate()),
        ];
    }
}
