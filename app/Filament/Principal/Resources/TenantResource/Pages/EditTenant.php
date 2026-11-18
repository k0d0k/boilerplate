<?php

namespace App\Filament\Principal\Resources\TenantResource\Pages;

use App\Filament\Principal\Resources\TenantResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Phase 7D — edit a tenant.
 *
 * There is no delete action: tenants are suspended, never destroyed, so their
 * isolated database and history are preserved.
 */
class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
