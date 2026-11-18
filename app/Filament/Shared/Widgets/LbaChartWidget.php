<?php

namespace App\Filament\Shared\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * Shared premium chart widget base (Chart.js, bundled with Filament — no extra
 * dashboard framework is added). Subclasses only declare the chart type and the
 * synthetic dataset; the LBA theme owns colours via the `fi-wi-chart-*-color`
 * hooks so all three panels render charts identically.
 */
abstract class LbaChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected string $color = 'primary';

    /**
     * @var array<string, mixed> | null
     */
    protected ?array $options = [
        'plugins' => [
            'legend' => [
                'display' => false,
            ],
        ],
        'tension' => 0.35,
    ];

    protected function getType(): string
    {
        return 'line';
    }
}
