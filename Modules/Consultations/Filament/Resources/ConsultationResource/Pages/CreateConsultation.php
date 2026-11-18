<?php

namespace Modules\Consultations\Filament\Resources\ConsultationResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\Consultations\Services\ConsultationManager;

/**
 * Workstream 2 — open a consultation in the verified tenant's isolated database.
 *
 * Creation goes through {@see ConsultationManager} so the consultation is
 * transactionally validated (tenant-local patient/branch/appointment, eligible
 * practitioner) and the initial status is written to the status history.
 */
class CreateConsultation extends CreateRecord
{
    protected static string $resource = ConsultationResource::class;

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

        try {
            return app(ConsultationManager::class)->create($data, $actor instanceof User ? $actor : null);
        } catch (ConsultationException $exception) {
            throw ValidationException::withMessages([
                'data.patient_id' => $exception->getMessage(),
            ]);
        }
    }
}
