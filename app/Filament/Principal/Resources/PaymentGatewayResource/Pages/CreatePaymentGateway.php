<?php

namespace App\Filament\Principal\Resources\PaymentGatewayResource\Pages;

use App\Filament\Principal\Resources\PaymentGatewayResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * WS3 — add a payment provider to the central configuration.
 */
class CreatePaymentGateway extends CreateRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PaymentGatewayResource::prepareData($data, null);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
