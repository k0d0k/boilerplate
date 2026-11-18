<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Services\ExaminationRegistrar;

/**
 * Phase 4 — start an examination in the verified tenant's isolated database.
 *
 * Creation goes through {@see ExaminationRegistrar} so the subject, branch and
 * optional appointment are validated tenant-locally and the examination is saved
 * as a draft with an explicit image-processing consent reference.
 */
class CreateExamination extends CreateRecord
{
    protected static string $resource = ExaminationResource::class;

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
            return app(ExaminationRegistrar::class)->create($data, $actor instanceof User ? $actor : null);
        } catch (ExaminationException $exception) {
            throw ValidationException::withMessages([
                'data.patient_id' => $exception->getMessage(),
            ]);
        }
    }
}
