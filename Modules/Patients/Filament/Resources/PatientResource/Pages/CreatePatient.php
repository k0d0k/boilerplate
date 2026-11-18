<?php

namespace Modules\Patients\Filament\Resources\PatientResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Patients\Services\PatientRegistrar;

/**
 * Phase 3 — register a patient in the verified tenant's isolated database.
 *
 * Creation goes through {@see PatientRegistrar} so the clinic patient number is
 * allocated safely and the creation is written to the audit trail.
 */
class CreatePatient extends CreateRecord
{
    protected static string $resource = PatientResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        return app(PatientRegistrar::class)->create($data, $actor instanceof User ? $actor : null);
    }
}
