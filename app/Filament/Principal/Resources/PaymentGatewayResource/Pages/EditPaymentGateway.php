<?php

namespace App\Filament\Principal\Resources\PaymentGatewayResource\Pages;

use App\Filament\Principal\Resources\PaymentGatewayResource;
use App\Models\PaymentGateway;
use Filament\Resources\Pages\EditRecord;

/**
 * WS3 — edit a payment provider's configuration.
 *
 * Secrets are never pre-filled: an operator can only replace a stored
 * credential by typing a new value, never read the existing one back.
 */
class EditPaymentGateway extends EditRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $config = (array) ($data['config'] ?? []);

        $data['config_webhook_id'] = $config['webhook_id'] ?? null;

        // Never expose the encrypted payload to the form state (and therefore
        // never to the rendered page or the Livewire snapshot).
        unset($data['credentials']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        return PaymentGatewayResource::prepareData($data, $record instanceof PaymentGateway ? $record : null);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
