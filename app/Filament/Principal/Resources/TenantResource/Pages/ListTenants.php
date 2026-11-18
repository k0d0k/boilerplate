<?php

namespace App\Filament\Principal\Resources\TenantResource\Pages;

use App\Filament\Principal\Resources\TenantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Phase 7D — list the central tenant registry.
 */
class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(static fn (): bool => TenantResource::canCreate()),
        ];
    }
}
