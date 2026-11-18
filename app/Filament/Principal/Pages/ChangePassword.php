<?php

namespace App\Filament\Principal\Pages;

use App\Filament\Shared\Concerns\ChangesOwnPassword;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * WS6.D — Principal account/profile page: change YOUR OWN password.
 *
 * Reachable by any authenticated principal identity. The acting identity is
 * resolved from the session, never from the request, so this page can only
 * change the signed-in account's own password.
 */
class ChangePassword extends Page
{
    use ChangesOwnPassword;

    protected static ?string $slug = 'change-password';

    // Reachable from the account menu (top right), not the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 90;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected string $view = 'filament.shared.pages.change-password';

    public static function getNavigationLabel(): string
    {
        return __('lba.password_change.nav');
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
