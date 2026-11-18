<?php

namespace Modules\Patients\Filament\Resources\PatientResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Patients\Models\Patient;

/**
 * Phase 3 — read-only patient profile, including consent and appointment
 * history relation managers.
 */
class ViewPatient extends ViewRecord
{
    protected static string $resource = PatientResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('lba.patients.edit'))
                ->url(fn (Patient $record): string => static::getResource()::getUrl('edit', ['record' => $record]))
                ->visible(fn (Patient $record): bool => static::getResource()::canEdit($record)),
        ];
    }
}
