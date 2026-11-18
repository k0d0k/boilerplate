<?php

namespace Modules\Commerce\Filament\Resources\ProductResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\ProductResource;
use Modules\Commerce\Services\ProductCatalog;

/**
 * WS3 — create a product in the verified tenant's isolated database.
 *
 * Creation goes through {@see ProductCatalog} so a stable SKU/slug and a valid
 * currency are always assigned. Filament saves the currency-specific price
 * repeater relationship straight after the record is created.
 */
class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        return app(ProductCatalog::class)->create($data, $actor instanceof User ? $actor : null);
    }
}
