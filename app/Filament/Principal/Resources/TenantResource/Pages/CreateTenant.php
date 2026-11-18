<?php

namespace App\Filament\Principal\Resources\TenantResource\Pages;

use App\Filament\Principal\Resources\TenantResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Phase 7D — register a tenant in the central registry.
 *
 * Registration is metadata only; the isolated tenant database is provisioned
 * separately (never implicitly) from the tenant list.
 */
class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
