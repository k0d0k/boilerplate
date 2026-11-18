<?php

namespace App\Filament\Clinic\Resources\TenantBranchResource\Pages;

use App\Filament\Clinic\Resources\TenantBranchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Phase 2B — list the verified tenant's branches.
 *
 * Reachable with zero branches: the header "create" action lets a management
 * role create the tenant's first branch without a branch-context deadlock.
 */
class ListBranches extends ListRecords
{
    protected static string $resource = TenantBranchResource::class;

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
