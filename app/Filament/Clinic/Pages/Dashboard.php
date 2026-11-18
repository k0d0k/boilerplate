<?php

namespace App\Filament\Clinic\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Clinic operations dashboard ("Dasbor Klinik"). The shared hero widget renders
 * the premium page header, so Filament's own header is suppressed.
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string|Htmlable
    {
        return __('lba.dashboard.clinic_title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }
}
