<?php

namespace App\Filament\Shared\Widgets;

use App\Filament\Shared\Data\LbaDemoData;
use Filament\Widgets\Widget;

/**
 * Shared premium "hero" header block used at the top of every panel dashboard.
 *
 * Presentation is identical across panels; only the synthetic copy differs, and
 * that copy is supplied per panel from {@see LbaDemoData}. No business logic and
 * no database access live here.
 */
class HeroSectionWidget extends Widget
{
    protected static bool $isDiscovered = false;

    /** Always renders first on a dashboard. */
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var view-string
     */
    protected string $view = 'filament.lba.widgets.hero';

    /** Key into {@see LbaDemoData}. */
    public string $dataset = '';

    protected function getViewData(): array
    {
        $userName = filament()->auth()->user()?->name;
        $firstName = filled($userName) ? str($userName)->before(' ')->toString() : null;

        return [
            ...LbaDemoData::get($this->dataset),
            'userName' => $firstName,
        ];
    }
}
