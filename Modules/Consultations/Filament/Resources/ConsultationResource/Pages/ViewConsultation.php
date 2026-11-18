<?php

namespace Modules\Consultations\Filament\Resources\ConsultationResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\Consultations\Models\Consultation;

/**
 * Workstream 2 — read-only consultation view, including the status history and
 * the SINA conversation transcript.
 */
class ViewConsultation extends ViewRecord
{
    protected static string $resource = ConsultationResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('lba.consultations.edit'))
                ->url(fn (Consultation $record): string => static::getResource()::getUrl('edit', ['record' => $record]))
                ->visible(fn (Consultation $record): bool => static::getResource()::canEdit($record)),
        ];
    }
}
