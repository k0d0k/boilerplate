<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Modules\Practitioners\Services\PractitionerRegistry;

/**
 * WS7.A — edit a practitioner's professional profile.
 *
 * Only the non-sensitive profile fields are writable here; status transitions
 * (verify / suspend / reactivate / archive) are separate audited record actions
 * on the resource.
 */
class EditPractitioner extends EditRecord
{
    protected static string $resource = PractitionerResource::class;

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

        if (! $actor instanceof User) {
            throw PractitionerException::notAuthorized();
        }

        return app(PractitionerRegistry::class)->update($actor, $record, $data);
    }
}
