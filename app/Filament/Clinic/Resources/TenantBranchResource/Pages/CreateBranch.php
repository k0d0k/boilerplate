<?php

namespace App\Filament\Clinic\Resources\TenantBranchResource\Pages;

use App\Filament\Clinic\Resources\TenantBranchResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Phase 2B — create a branch in the verified tenant's isolated database.
 */
class CreateBranch extends CreateRecord
{
    protected static string $resource = TenantBranchResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
