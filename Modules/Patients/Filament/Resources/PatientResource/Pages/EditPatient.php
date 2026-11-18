<?php

namespace Modules\Patients\Filament\Resources\PatientResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Patients\Services\PatientRegistrar;

/**
 * Phase 3 — edit a patient of the verified tenant.
 *
 * There is no delete action: patients are archived, never destroyed.
 */
class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();

        return app(PatientRegistrar::class)->update($record, $data, $actor instanceof User ? $actor : null);
    }
}
