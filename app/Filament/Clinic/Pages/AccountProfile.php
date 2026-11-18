<?php

namespace App\Filament\Clinic\Pages;

use App\Filament\Shared\Concerns\ManagesAccountProfile;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * WS9.E — Clinic account profile.
 *
 * Maintains ONLY the signed-in staff member's own LOGIN account (display name,
 * login email, phone, description) and links to the existing change-password
 * flow. It is separate from any patient record and never reads tenant clinical
 * data; a login-email change requires the current password and a verification of
 * the new address before it is used.
 */
class AccountProfile extends Page
{
    use ManagesAccountProfile;

    protected static ?string $slug = 'account-profile';

    // The account menu (top right) is the single entry point; this page must
    // not also appear as its own sidebar item.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 91;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected string $view = 'filament.shared.pages.account-profile';

    public static function getNavigationLabel(): string
    {
        return __('lba.account.nav');
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
