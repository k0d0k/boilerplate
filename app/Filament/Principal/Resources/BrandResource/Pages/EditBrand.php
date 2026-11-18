<?php

namespace App\Filament\Principal\Resources\BrandResource\Pages;

use App\Filament\Principal\Resources\BrandResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Phase 7D — edit a brand.
 *
 * There is no delete action: brands are suspended, never destroyed.
 */
class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
