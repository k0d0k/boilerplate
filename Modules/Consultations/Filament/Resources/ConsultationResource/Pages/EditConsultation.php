<?php

namespace Modules\Consultations\Filament\Resources\ConsultationResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\Consultations\Services\ConsultationManager;

/**
 * Workstream 2 — reschedule a consultation (branch, practitioner, time, notes).
 *
 * The update goes through {@see ConsultationManager::schedule()} so the same
 * tenant-local validation applies and the status lifecycle stays on its own
 * validated path.
 */
class EditConsultation extends EditRecord
{
    protected static string $resource = ConsultationResource::class;

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

        try {
            return app(ConsultationManager::class)->schedule($record, $data, $actor instanceof User ? $actor : null);
        } catch (ConsultationException $exception) {
            throw ValidationException::withMessages([
                'data.patient_id' => $exception->getMessage(),
            ]);
        }
    }
}
