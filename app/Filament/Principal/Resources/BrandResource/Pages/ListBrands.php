<?php

namespace App\Filament\Principal\Resources\BrandResource\Pages;

use App\Filament\Principal\Resources\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Phase 7D — list the central brand catalog.
 */
class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(static fn (): bool => BrandResource::canCreate()),
        ];
    }
}
