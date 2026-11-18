<?php

namespace App\Filament\Principal\Widgets;

use App\Filament\Shared\Widgets\LbaStatsWidget;
use App\Platform\Analytics\PlatformAnalytics;
use Filament\Support\Icons\Heroicon;

/**
 * WS4.3 — Principal KPI strip, backed by REAL central governance data.
 *
 * Every figure is computed by {@see PlatformAnalytics} from CENTRAL tables only
 * (tenants, the tenant-database registry, subscriptions and the AI credit
 * ledger). No tenant clinical connection is ever opened and no patient record is
 * read, so the strip stays strictly non-clinical.
 */
class PlatformOverviewWidget extends LbaStatsWidget
{
    /**
     * Four KPI cards across on desktop, two on tablet, one on mobile. Without an
     * explicit column map Filament falls back to a container query that never
     * drops below three columns, which crushed the cards on narrow screens.
     *
     * @var array<string, int>
     */
    protected int|array|null $columns = ['default' => 1, 'md' => 2, 'xl' => 4];

    protected function getHeading(): ?string
    {
        return __('lba.widget.platform_heading');
    }

    protected function getDescription(): ?string
    {
        return __('lba.widget.platform_description');
    }

    protected function getStats(): array
    {
        $analytics = app(PlatformAnalytics::class);

        $partners = $analytics->partners();
        $databases = $analytics->tenantDatabases();
        $subscriptions = $analytics->subscriptions();
        $credits = $analytics->aiCredits();
        $usage = $analytics->usageTrend();

        return [
            $this->kpi(
                __('lba.kpi.principal.partners_label'),
                (string) $partners['total'],
                __('lba.kpi.principal.partners_desc', [
                    'active' => $partners['active'],
                    'suspended' => $partners['suspended'],
                ]),
                $analytics->partnerGrowth(),
                'blue',
                Heroicon::OutlinedBuildingOffice2,
            )->extraAttributes(['class' => 'lba-kpi lba-kpi--blue']),
            $this->kpi(
                __('lba.kpi.principal.databases_label'),
                (string) $databases['migrated'],
                __('lba.kpi.principal.databases_desc', [
                    'pending' => $databases['pending'],
                    'failed' => $databases['failed'],
                ]),
                $analytics->databaseGrowth(),
                'teal',
                Heroicon::OutlinedCircleStack,
            )->extraAttributes(['class' => 'lba-kpi lba-kpi--teal']),
            $this->kpi(
                __('lba.kpi.principal.subscriptions_label'),
                (string) $subscriptions['active'],
                __('lba.kpi.principal.subscriptions_desc', [
                    'pending' => $subscriptions['pending'],
                ]),
                $analytics->subscriptionGrowth(),
                'purple',
                Heroicon::OutlinedCreditCard,
            )->extraAttributes(['class' => 'lba-kpi lba-kpi--purple']),
            $this->kpi(
                __('lba.kpi.principal.ai_credits_label'),
                number_format($credits['consumed']),
                __('lba.kpi.principal.ai_credits_desc', [
                    'available' => number_format($credits['available']),
                ]),
                $usage['ai'],
                'amber',
                Heroicon::OutlinedCpuChip,
            )->extraAttributes(['class' => 'lba-kpi lba-kpi--amber']),
        ];
    }
}
