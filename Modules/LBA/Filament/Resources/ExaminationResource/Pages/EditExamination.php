<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Services\ExaminationRegistrar;

/**
 * Phase 4 — edit the permitted draft data of an editable examination.
 *
 * The status is not editable here: the lifecycle only moves through validated
 * transitions (see the resource's workflow actions). Only editable statuses can
 * reach this page (enforced by the resource's `canEdit`).
 */
class EditExamination extends EditRecord
{
    protected static string $resource = ExaminationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();

        try {
            return app(ExaminationRegistrar::class)->update($record, $data, $actor instanceof User ? $actor : null);
        } catch (ExaminationException $exception) {
            throw ValidationException::withMessages([
                'data.patient_id' => $exception->getMessage(),
            ]);
        }
    }
}
