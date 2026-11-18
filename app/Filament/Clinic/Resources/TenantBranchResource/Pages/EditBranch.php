<?php

namespace App\Filament\Clinic\Resources\TenantBranchResource\Pages;

use App\Filament\Clinic\Resources\TenantBranchResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Phase 2B — edit a branch of the verified tenant.
 *
 * There is no delete action: branches are deactivated, never destroyed.
 */
class EditBranch extends EditRecord
{
    protected static string $resource = TenantBranchResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
