<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 8A — "Profil".
 *
 * Shows, and lets the patient maintain, their OWN contact details. `full_name`,
 * `date_of_birth`, `sex`/`gender`, the tenant-local patient number and the
 * clinic registration are clinical-of-record fields: they stay owned by the
 * clinic and are read-only here. Only the contact fields a patient may
 * legitimately self-serve are editable.
 */
class Profile extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'profil';

    protected static ?int $navigationSort = 14;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected string $view = 'filament.patient.pages.profile';

    public ?string $phone = null;

    public ?string $email = null;

    public ?string $address = null;

    public ?string $city = null;

    public ?string $region = null;

    public ?string $country = null;

    public static function canAccess(): bool
    {
        return static::hasPatientContext();
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.profile');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.profile.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.profile.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.profile.subtitle');
    }

    public function mount(): void
    {
        $patient = $this->patient();

        if ($patient === null) {
            return;
        }

        $this->phone = $patient->phone;
        $this->email = $patient->email;
        $this->address = $patient->address;
        $this->city = $patient->city;
        $this->region = $patient->region;
        $this->country = $patient->country;
    }

    public function patientNumber(): ?string
    {
        return $this->patient()?->patient_number;
    }

    public function fullName(): ?string
    {
        return $this->patient()?->full_name;
    }

    public function save(): void
    {
        // Self-only: the update is always against the acting identity's OWN
        // patient record, resolved server-side — never from a client id.
        $patient = $this->requirePatient();

        $validated = $this->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        $patient->update($validated);

        Notification::make()
            ->success()
            ->title(__('lba.patient.profile.saved'))
            ->send();
    }
}
