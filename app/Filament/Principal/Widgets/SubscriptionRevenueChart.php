<?php

namespace App\Filament\Principal\Widgets;

use App\Filament\Shared\Widgets\LbaChartWidget;
use App\Platform\Analytics\PlatformAnalytics;
use Illuminate\Contracts\Support\Htmlable;

/**
 * WS4.3 — REAL monthly recurring revenue trend for the Principal dashboard.
 *
 * The series is derived from live central subscriptions normalised to a monthly
 * value by {@see PlatformAnalytics::revenueTrend()}. One dataset is emitted per
 * billing currency, so IDR and MYR licences are never summed into a single,
 * meaningless figure. Strictly non-clinical aggregate data.
 */
class SubscriptionRevenueChart extends LbaChartWidget
{
    protected static ?int $sort = 2;

    protected string $color = 'primary';

    /**
     * @var array<string, mixed> | null
     */
    protected ?array $options = [
        'plugins' => [
            'legend' => [
                'display' => true,
            ],
        ],
        'tension' => 0.35,
    ];

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.widget.revenue_heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('lba.widget.revenue_description');
    }

    /**
     * The series carries one dataset per billing currency, so an empty dataset
     * list means no subscription has ever been billed. The month labels are
     * always present, which is why the framework's default `isEmpty()` would
     * otherwise render a blank chart instead of a clear empty state.
     */
    public function isEmpty(): bool
    {
        return ($this->getCachedData()['datasets'] ?? []) === [];
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return __('lba.widget.revenue_empty_heading');
    }

    public function getEmptyStateDescription(): string|Htmlable|null
    {
        return __('lba.widget.revenue_empty_description');
    }

    protected function getData(): array
    {
        $trend = app(PlatformAnalytics::class)->revenueTrend();

        $datasets = [];

        foreach ($trend['series'] as $currency => $values) {
            $datasets[] = [
                'label' => __('lba.widget.revenue_dataset', ['currency' => $currency]),
                'data' => $values,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $trend['labels'],
        ];
    }
}
