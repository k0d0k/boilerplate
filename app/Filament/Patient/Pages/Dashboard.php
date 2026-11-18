<?php

namespace App\Filament\Patient\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Patient portal home ("Beranda Pasien").
 *
 * Mobile-first: the dashboard grid is a single column until a large viewport,
 * and the shared hero widget renders the friendly greeting header.
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string|Htmlable
    {
        return __('lba.dashboard.patient_title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return int | array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }
}
