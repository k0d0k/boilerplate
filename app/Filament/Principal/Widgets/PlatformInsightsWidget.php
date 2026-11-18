<?php

namespace App\Filament\Principal\Widgets;

use App\Platform\Analytics\PlatformAnalytics;
use Filament\Widgets\Widget;

/**
 * WS4.3 — real Principal insight cards.
 *
 * One widget class renders two registrations (selected by `section`):
 *
 *  - `activity`: the most recent redacted module lifecycle events from the
 *    central audit log — module keys, actions and outcomes only, never clinical.
 *  - `health`:   platform readiness summary (tenant databases, subscriptions and
 *    AI credit position) computed from central registry counts.
 *
 * Presentation reuses the shared premium section partial (list variant), so the
 * card shell, spacing and typography match every other dashboard card. No
 * clinical connection is opened and no patient data is read.
 */
class PlatformInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    /** Renders with the other section cards, after the KPI strip and charts. */
    protected static ?int $sort = 10;

    /**
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = 1;

    /**
     * Public, registration-settable span (mirrors {@see $columnSpan}).
     *
     * @var int | string | array<string, int | null>
     */
    public int|string|array $span = 1;

    /**
     * @var view-string
     */
    protected string $view = 'filament.lba.widgets.section';

    /** Section to render: activity | health. */
    public string $section = 'activity';

    /**
     * @return int | string | array<string, int | null>
     */
    public function getColumnSpan(): int|string|array
    {
        return $this->span;
    }

    protected function getViewData(): array
    {
        $data = $this->section === 'health'
            ? $this->health()
            : $this->activity();

        return [
            'variant' => 'list',
            'heading' => $data['heading'],
            'description' => $data['description'],
            'items' => $data['items'],
            'primary' => null,
            'secondary' => null,
            'progress' => null,
            'progressLabel' => null,
            'note' => null,
        ];
    }

    /**
     * The most recent redacted module lifecycle events.
     *
     * @return array{heading: string, description: string, items: list<array<string, mixed>>}
     */
    private function activity(): array
    {
        $items = [];

        foreach (app(PlatformAnalytics::class)->recentModuleActivity(5) as $entry) {
            $result = (string) $entry->result;

            $items[] = [
                'title' => (string) $entry->action,
                'subtitle' => (string) $entry->module_key,
                'meta' => $entry->created_at?->diffForHumans() ?? '',
                'badge' => [
                    'label' => __("lba.analytics.result_{$result}"),
                    'tone' => $result === 'allowed' ? 'teal' : 'rose',
                ],
            ];
        }

        return [
            'heading' => __('lba.analytics.activity_heading'),
            'description' => __('lba.analytics.activity_description'),
            'items' => $items,
        ];
    }

    /**
     * Platform readiness summary from central registry counts.
     *
     * @return array{heading: string, description: string, items: list<array<string, mixed>>}
     */
    private function health(): array
    {
        $analytics = app(PlatformAnalytics::class);

        $databases = $analytics->tenantDatabases();
        $subscriptions = $analytics->subscriptions();
        $credits = $analytics->aiCredits();

        return [
            'heading' => __('lba.analytics.health_heading'),
            'description' => __('lba.analytics.health_description'),
            'items' => [
                [
                    'title' => __('lba.analytics.health_databases'),
                    'subtitle' => __('lba.analytics.health_databases_value', [
                        'migrated' => $databases['migrated'],
                        'pending' => $databases['pending'],
                    ]),
                    'meta' => '',
                    'badge' => [
                        'label' => $databases['failed'] === 0
                            ? __('lba.analytics.state_ready')
                            : __('lba.analytics.state_attention'),
                        'tone' => $databases['failed'] === 0 ? 'teal' : 'rose',
                    ],
                ],
                [
                    'title' => __('lba.analytics.health_subscriptions'),
                    'subtitle' => __('lba.analytics.health_subscriptions_value', [
                        'active' => $subscriptions['active'],
                        'pending' => $subscriptions['pending'],
                    ]),
                    'meta' => '',
                    'badge' => [
                        'label' => __('lba.analytics.state_normal'),
                        'tone' => 'teal',
                    ],
                ],
                [
                    'title' => __('lba.analytics.health_credits'),
                    'subtitle' => __('lba.analytics.health_credits_value', [
                        'available' => number_format($credits['available']),
                        'reserved' => number_format($credits['reserved']),
                    ]),
                    'meta' => '',
                    'badge' => [
                        'label' => __('lba.analytics.state_normal'),
                        'tone' => 'teal',
                    ],
                ],
            ],
        ];
    }
}
