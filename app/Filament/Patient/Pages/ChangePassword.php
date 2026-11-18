<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Shared\Concerns\ChangesOwnPassword;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * WS6.D — Patient account page: change YOUR OWN password.
 *
 * Reachable by any authenticated patient identity. The acting identity is
 * resolved from the session, never from the request, so this page can only
 * change the signed-in account's own password.
 */
class ChangePassword extends Page
{
    use ChangesOwnPassword;

    protected static ?string $slug = 'ubah-kata-sandi';

    protected static ?int $navigationSort = 15;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected string $view = 'filament.shared.pages.change-password';

    public static function getNavigationLabel(): string
    {
        return __('lba.password_change.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.password_change.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.password_change.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.password_change.subtitle');
    }
}
