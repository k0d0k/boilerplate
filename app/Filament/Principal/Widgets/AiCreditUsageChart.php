<?php

namespace App\Filament\Principal\Widgets;

use App\Filament\Shared\Widgets\LbaChartWidget;
use App\Platform\Analytics\PlatformAnalytics;
use Illuminate\Contracts\Support\Htmlable;

/**
 * WS4.3 — REAL AI (LBA) and SINA credit consumption trend.
 *
 * Read from the immutable central usage records through
 * {@see PlatformAnalytics::usageTrend()}, aggregated per month. Only integer
 * accounting totals are shown — SINA per-patient identity is never surfaced on
 * this non-clinical governance panel.
 */
class AiCreditUsageChart extends LbaChartWidget
{
    protected static ?int $sort = 3;

    protected string $color = 'navy';

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
        return __('lba.widget.ai_heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('lba.widget.ai_description');
    }

    /**
     * The trend always returns six month buckets, so the framework's default
     * `isEmpty()` never fires even when nothing has been consumed yet. Treat an
     * all-zero series as "no data" so the card shows a clear empty state rather
     * than a flat line along the axis.
     */
    public function isEmpty(): bool
    {
        $total = 0;

        foreach ($this->getCachedData()['datasets'] ?? [] as $dataset) {
            $total += array_sum($dataset['data'] ?? []);
        }

        return $total === 0;
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return __('lba.widget.ai_empty_heading');
    }

    public function getEmptyStateDescription(): string|Htmlable|null
    {
        return __('lba.widget.ai_empty_description');
    }

    protected function getData(): array
    {
        $trend = app(PlatformAnalytics::class)->usageTrend();

        return [
            'datasets' => [
                [
                    'label' => __('lba.widget.ai_dataset'),
                    'data' => $trend['ai'],
                ],
                [
                    'label' => __('lba.widget.sina_dataset'),
                    'data' => $trend['sina'],
                ],
            ],
            'labels' => $trend['labels'],
        ];
    }
}
