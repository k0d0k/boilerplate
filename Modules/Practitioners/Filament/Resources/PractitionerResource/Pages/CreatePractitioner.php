<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Modules\Practitioners\Services\PractitionerRegistry;

/**
 * WS7.A — register a practitioner in the verified tenant's isolated database.
 *
 * Creation goes through {@see PractitionerRegistry}: the central `user_id` is
 * never mass-assignable and is written server-side only after the registry has
 * confirmed the target holds an active clinical membership. The profile is
 * created `pending` — registration is never verification.
 */
class CreatePractitioner extends CreateRecord
{
    protected static string $resource = PractitionerResource::class;

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

        if (! $actor instanceof User) {
            throw PractitionerException::notAuthorized();
        }

        return app(PractitionerRegistry::class)->register(
            $actor,
            (string) ($data['user_id'] ?? ''),
            $data,
        );
    }
}
