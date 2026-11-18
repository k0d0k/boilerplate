<?php

namespace App\Filament\Shared\Widgets;

use BackedEnum;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Shared premium KPI strip (the "KPI card component" of the LBA dashboards).
 *
 * Filament renders the cards natively; the LBA theme owns the visual treatment
 * (radius, border, shadow, typography). This base only centralises the assembly
 * of demo {@see Stat} objects so each panel declares its four KPIs in one place
 * and shares the same sparkline + description conventions.
 */
abstract class LbaStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    /** Renders directly below the hero band. */
    protected static ?int $sort = 1;

    /**
     * Build a premium KPI card backed by an optional synthetic sparkline.
     *
     * @param  array<int, int> | null  $chart
     */
    protected function kpi(
        string $label,
        string $value,
        string $description,
        ?array $chart = null,
        string $color = 'primary',
        string|BackedEnum|Htmlable|null $icon = null,
    ): Stat {
        $stat = Stat::make($label, $value)
            ->description($description)
            ->color($color);

        if ($icon !== null) {
            $stat->icon($icon);
        }

        if ($chart !== null) {
            $stat->chart($chart);
        }

        return $stat;
    }
}
