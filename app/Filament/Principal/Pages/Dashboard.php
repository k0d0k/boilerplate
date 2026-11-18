<?php

namespace App\Filament\Principal\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Principal governance dashboard ("Ringkasan Platform").
 *
 * The premium header (page title, welcome, reporting period) is rendered by the
 * shared hero widget, so Filament's own page header is suppressed to avoid a
 * duplicated title.
 *
 * The page renders inside the TailAdmin shell (the SAME shell the TailAdmin
 * governance screens use), so a principal never sees the shell switch when
 * moving from the dashboard to Module Manager and back. Only the layout differs
 * from Filament's default; the widget composition is unchanged.
 */
class Dashboard extends BaseDashboard
{
    protected static string $layout = 'principal.layouts.filament';

    public function getTitle(): string|Htmlable
    {
        return __('lba.dashboard.principal_title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getLayoutData(): array
    {
        return ['title' => (string) __('lba.dashboard.principal_title')];
    }
}
