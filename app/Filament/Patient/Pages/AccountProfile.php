<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Filament\Shared\Concerns\ManagesAccountProfile;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * WS9.E — Patient account profile.
 *
 * Maintains ONLY the signed-in identity's own LOGIN account (display name,
 * login email, phone, description) and links to the existing change-password
 * flow. It is deliberately SEPARATE from the patient chart: the portal's "Profil"
 * page keeps the clinical-of-record patient data, while this page only ever
 * touches the login identity. A login-email change requires the current password
 * and a verification of the new address before it is used.
 */
class AccountProfile extends Page
{
    use AuthorizesPatientPortal, ManagesAccountProfile;

    protected static ?string $slug = 'profil-akun';

    protected static ?int $navigationSort = 16;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected string $view = 'filament.shared.pages.account-profile';

    public static function canAccess(): bool
    {
        return static::hasPatientContext();
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.account.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.account.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.account.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.account.subtitle');
    }

    protected static function changePasswordPageClass(): string
    {
        return ChangePassword::class;
    }
}
