<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Modules\Practitioners\Models\Practitioner;

/**
 * WS7.A — read-only practitioner profile, including credentials, branch
 * authorizations and the practitioner's own audit trail.
 */
class ViewPractitioner extends ViewRecord
{
    protected static string $resource = PractitionerResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('lba.practitioners.edit'))
                ->url(fn (Practitioner $record): string => static::getResource()::getUrl('edit', ['record' => $record]))
                ->visible(fn (Practitioner $record): bool => static::getResource()::canEdit($record)),
        ];
    }
}
