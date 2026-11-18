<?php

namespace App\Filament\Principal\Resources\BrandResource\Pages;

use App\Filament\Principal\Resources\BrandResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Phase 7D — create a brand in the central catalog.
 */
class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
